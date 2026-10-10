<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
use App\Models\TargetRow;
use App\Support\ProjectSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Terapkan laporan hasil kerja userscript (JSON atau CSV) ke status baris dalam satu unit
 * (kecamatan / Bagian / file). Baris dicocokkan lewat kolom kunci atau nomor baris sesuai pengaturan data.
 */
class StatusReportImporter
{
    private const STATUS_FIELDS = ['status', 'status_label', 'hasil'];

    private const REASON_FIELDS = ['reason', 'alasan', 'keterangan'];

    private const TIME_FIELDS = ['doneAt', 'done_at', 'waktu', 'time'];

    /** Field hasil rinci yang bisa memperjelas status selesai (lihat parse()). */
    private const DETAIL_FIELDS = ['linkResult', 'link_result'];

    private const MAX_RESULT_FIELDS = 40;

    /** Penanda sheet untuk laporan yang hanya menyebut nomor baris; diganti sheet milik unit saat diterapkan. */
    private const ANY_SHEET = '*';

    /**
     * Statistik pembacaan terakhir, untuk menjelaskan kenapa laporan tidak cocok.
     *
     * @var array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}
     */
    private array $lastParse = ['items' => 0, 'without_key' => 0, 'unknown_statuses' => [], 'sample_keys' => []];

    /**
     * Terapkan laporan ke unit. Baris yang kuncinya milik unit lain pekerjaan ini (mis. baris lama yang ikut
     * di file Bagian baru, atau file "belum selesai" gabungan) diteruskan ke unit pemiliknya.
     *
     * @return array{records: int, matched: int, updated: int, skipped: int, unmatched: int, forwarded: array<string, int>, statuses: array<string, int>, unit: string, parse: array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}}
     */
    public function import(ReportFile $file, Kecamatan $unit): array
    {
        $settings = $unit->project->config();
        $records = $this->forUnitSheet($this->parse(Storage::disk('local')->get($file->path), $file->extension, $settings), $unit);

        $rows = TargetRow::where('kecamatan_id', $unit->id)
            ->tracked()
            ->whereIn('row_key', array_keys($records))
            ->get()
            ->groupBy('row_key');
        $elsewhere = TargetRow::where('project_id', $unit->project_id)
            ->where('kecamatan_id', '!=', $unit->id)
            ->tracked()
            ->whereIn('row_key', array_diff(array_keys($records), $rows->keys()->all()))
            ->with('kecamatan.project')
            ->get()
            ->groupBy('row_key');

        $defaultCode = $settings->statuses()->defaultCode();
        $summary = ['records' => count($records), 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'unmatched' => 0, 'forwarded' => [], 'statuses' => [], 'unit' => $unit->nama, 'parse' => $this->lastParse];
        $touched = collect();

        foreach ($records as $rowKey => $record) {
            if ($rows->has($rowKey)) {
                $summary['matched']++;

                foreach ($rows[$rowKey] as $row) {
                    if ($this->apply($row, $record, $file, $defaultCode)) {
                        $summary['updated']++;
                        $summary['statuses'][$record['status']] = ($summary['statuses'][$record['status']] ?? 0) + 1;
                    } else {
                        $summary['skipped']++;
                    }
                }
            } elseif ($elsewhere->has($rowKey)) {
                foreach ($elsewhere[$rowKey] as $row) {
                    if ($this->apply($row, $record, $file, $defaultCode)) {
                        $summary['forwarded'][$row->kecamatan->nama] = ($summary['forwarded'][$row->kecamatan->nama] ?? 0) + 1;
                        $touched->put($row->kecamatan_id, $row->kecamatan);
                    }
                }
            } else {
                $summary['unmatched']++;
            }
        }

        $unit->syncProgressFromTargets();
        $touched->each(fn (Kecamatan $other) => $other->syncProgressFromTargets());

        return $summary;
    }

    /**
     * Terapkan ulang laporan unit lain ke baris milik $owner saja (dipakai saat $owner direset & diaktifkan ulang,
     * supaya hasil yang dulu diteruskan dari unit lain tidak hilang).
     */
    public function applyTo(ReportFile $file, Kecamatan $owner): int
    {
        if (! Storage::disk('local')->exists($file->path)) {
            return 0;
        }

        $settings = $owner->project->config();
        $records = $this->forUnitSheet($this->parse(Storage::disk('local')->get($file->path), $file->extension, $settings), $owner);
        $defaultCode = $settings->statuses()->defaultCode();
        $applied = 0;

        TargetRow::where('kecamatan_id', $owner->id)
            ->tracked()
            ->whereIn('row_key', array_keys($records))
            ->get()
            ->each(function (TargetRow $row) use ($records, $file, $defaultCode, &$applied) {
                $applied += $this->apply($row, $records[$row->row_key], $file, $defaultCode) ? 1 : 0;
            });

        if ($applied > 0) {
            $owner->syncProgressFromTargets();
        }

        return $applied;
    }

    /**
     * Kunci "*!4" (laporan tanpa nama sheet) memakai sheet file unit, mis. "bagian 1!4".
     *
     * @param  array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>}>  $records
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>}>
     */
    private function forUnitSheet(array $records, Kecamatan $unit): array
    {
        $prefix = self::ANY_SHEET.'!';
        $anySheet = array_filter(array_keys($records), fn (string $key) => str_starts_with($key, $prefix));

        if ($anySheet === []) {
            return $records;
        }

        $sample = TargetRow::where('kecamatan_id', $unit->id)->where('row_key', 'like', '%!%')->value('row_key');
        $sheet = $sample !== null ? strstr($sample, '!', true) : 'sheet1';
        $resolved = [];

        foreach ($records as $key => $record) {
            $resolved[str_starts_with($key, $prefix) ? $sheet.'!'.substr($key, strlen($prefix)) : $key] = $record;
        }

        return $resolved;
    }

    /**
     * @param  array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>}  $record
     */
    private function apply(TargetRow $row, array $record, ReportFile $file, ?string $defaultCode): bool
    {
        if (! $this->shouldApply($row, $record, $defaultCode)) {
            return false;
        }

        $row->update([
            'status' => $record['status'],
            'reason' => $record['reason'],
            'result' => $record['result'] ?: null,
            'status_at' => $record['done_at'] ?? now(),
            'status_file_id' => $file->id,
        ]);

        return true;
    }

    /**
     * Laporan yang lebih lama tidak boleh menimpa status yang lebih baru.
     *
     * @param  array{status: string, done_at: ?Carbon}  $record
     */
    private function shouldApply(TargetRow $row, array $record, ?string $defaultCode): bool
    {
        if ($record['status'] === $defaultCode) {
            return $row->status === null || $row->status === $defaultCode;
        }

        if ($row->status_at && $record['done_at'] && $record['done_at']->lt($row->status_at)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>}>
     */
    public function parse(string $content, string $extension, ProjectSettings $settings): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $items = $extension === 'csv' ? $this->parseCsv($content) : $this->parseJson($content);
        $statuses = $settings->statuses();

        $records = [];
        $this->lastParse = ['items' => 0, 'without_key' => 0, 'unknown_statuses' => [], 'sample_keys' => []];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->lastParse['items']++;
            $rowKeys = $this->rowKeys($item, $settings);
            $rawStatus = $this->firstField($item, self::STATUS_FIELDS);
            $status = $statuses->resolve($rawStatus);

            // Hasil rinci dari script (mis. OSS: closed + linkResult "ganda"): bila cocok dengan status
            // selesai lain di pengaturan, pakai status yang lebih rinci itu
            $detail = $this->firstField($item, self::DETAIL_FIELDS);
            $refined = $detail !== null && $statuses->isDone($status) ? $statuses->resolve($detail) : null;

            if ($refined !== null && $statuses->isDone($refined)) {
                $status = $refined;
            }

            if ($rowKeys === []) {
                $this->lastParse['without_key']++;

                continue;
            }

            if ($status === null) {
                $label = $rawStatus ?? '(kosong)';
                $this->lastParse['unknown_statuses'][$label] = ($this->lastParse['unknown_statuses'][$label] ?? 0) + 1;

                continue;
            }

            $doneAt = $this->firstField($item, self::TIME_FIELDS);
            $record = [
                'status' => $status,
                'reason' => $this->firstField($item, self::REASON_FIELDS),
                // Waktu dari script biasanya UTC ("…Z"): simpan dalam zona waktu aplikasi (WITA)
                'done_at' => $doneAt ? rescue(fn () => Carbon::parse($doneAt)->setTimezone(config('app.timezone')), null, false) : null,
                'result' => $this->result($item),
            ];

            foreach ($rowKeys as $rowKey => $target) {
                if (count($this->lastParse['sample_keys']) < 2) {
                    $this->lastParse['sample_keys'][] = $rowKey;
                }

                $records[$rowKey] = $target === [] ? $record : [...$record, 'result' => array_slice([...$record['result'], ...$target], 0, self::MAX_RESULT_FIELDS, true)];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $fields
     */
    private function firstField(array $item, array $fields): ?string
    {
        $item = array_change_key_case($item, CASE_LOWER);

        foreach ($fields as $field) {
            $field = strtolower($field);

            if (isset($item[$field]) && is_scalar($item[$field]) && trim((string) $item[$field]) !== '') {
                return trim((string) $item[$field]);
            }
        }

        return null;
    }

    /**
     * Kunci baris dari satu item laporan, beserta detail per baris. Item antrean bisa mewakili beberapa
     * baris Excel sekaligus lewat "targets": [{"row": 2, "nama": …}, …].
     *
     * @param  array<string, mixed>  $item
     * @return array<string, array<string, scalar>>
     */
    private function rowKeys(array $item, ProjectSettings $settings): array
    {
        $key = $this->rowKey($item, $settings);

        if ($key !== null) {
            return [$key => []];
        }

        if ($settings->keyMode() === ProjectSettings::KEY_COLUMN || ! is_array($item['targets'] ?? null)) {
            return [];
        }

        $keys = [];

        foreach ($item['targets'] as $target) {
            if (is_array($target) && is_numeric($target['row'] ?? null)) {
                $sheet = (string) ($target['sheet'] ?? $item['sheet'] ?? self::ANY_SHEET);
                $keys[TargetImporter::sheetRowKey($sheet, (int) $target['row'])] = array_filter(
                    array_diff_key($target, ['row' => 0, 'sheet' => 0, 'status' => 0]),
                    fn ($value) => is_scalar($value) && $value !== '',
                );
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rowKey(array $item, ProjectSettings $settings): ?string
    {
        if ($settings->keyMode() === ProjectSettings::KEY_COLUMN) {
            return TargetImporter::normalizeKey($this->firstField($item, $settings->reportKeyFields()));
        }

        $id = trim((string) ($item['id'] ?? ''));

        if (str_contains($id, '!')) {
            return strtolower($id);
        }

        $row = $this->firstField($item, [...$settings->reportKeyFields(), 'row', 'baris_excel']);

        return is_numeric($row)
            ? TargetImporter::sheetRowKey((string) ($item['sheet'] ?? self::ANY_SHEET), (int) $row)
            : null;
    }

    /**
     * Semua field lain dari laporan disimpan sebagai detail hasil (objek bertingkat diratakan satu level).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, scalar>
     */
    private function result(array $item): array
    {
        $skip = [...self::STATUS_FIELDS, ...self::REASON_FIELDS, ...self::TIME_FIELDS, 'values', 'candidates', 'targets'];
        $result = [];

        foreach ($item as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $value = implode('; ', array_filter($value, 'is_scalar'));
            }

            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue) && $subValue !== '') {
                        $result[$subKey] = $subValue;
                    }
                }

                continue;
            }

            if ($value !== null && $value !== '' && is_scalar($value)) {
                $result[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return array_slice($result, 0, self::MAX_RESULT_FIELDS, true);
    }

    /**
     * Format yang diterima: {"queue": [...]}, {"items": [...]}, {"data": [...]} atau array langsung.
     *
     * @return list<mixed>
     */
    private function parseJson(string $content): array
    {
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('File JSON tidak valid.');
        }

        foreach (['queue', 'items', 'data', 'rows'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        return array_is_list($data) ? $data : [];
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = array_map(fn ($header) => trim($header), str_getcsv(array_shift($lines), escape: ''));

        $items = [];
        $buffer = '';

        // Baris CSV bisa berisi newline di dalam tanda kutip, jadi gabungkan sampai kutipnya seimbang.
        foreach ($lines as $line) {
            $buffer = $buffer === '' ? $line : $buffer."\n".$line;

            if (substr_count($buffer, '"') % 2 !== 0) {
                continue;
            }

            $values = str_getcsv($buffer, escape: '');
            $buffer = '';

            if (count($values) === count($headers)) {
                $items[] = array_combine($headers, $values);
            }
        }

        return $items;
    }
}
