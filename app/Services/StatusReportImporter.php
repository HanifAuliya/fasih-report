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

    private const MAX_RESULT_FIELDS = 40;

    /**
     * @return array{records: int, matched: int, updated: int, skipped: int, unmatched: int, statuses: array<string, int>, unit: string}
     */
    public function import(ReportFile $file, Kecamatan $unit): array
    {
        $settings = $unit->project->config();
        $records = $this->parse(Storage::disk('local')->get($file->path), $file->extension, $settings);

        $rows = TargetRow::where('kecamatan_id', $unit->id)
            ->whereIn('row_key', array_keys($records))
            ->get()
            ->groupBy('row_key');

        $defaultCode = $settings->statuses()->defaultCode();
        $summary = ['records' => count($records), 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'unmatched' => 0, 'statuses' => [], 'unit' => $unit->nama];

        foreach ($records as $rowKey => $record) {
            if (! $rows->has($rowKey)) {
                $summary['unmatched']++;

                continue;
            }

            $summary['matched']++;

            foreach ($rows[$rowKey] as $row) {
                if (! $this->shouldApply($row, $record, $defaultCode)) {
                    $summary['skipped']++;

                    continue;
                }

                $row->update([
                    'status' => $record['status'],
                    'reason' => $record['reason'],
                    'result' => $record['result'] ?: null,
                    'status_at' => $record['done_at'] ?? now(),
                    'status_file_id' => $file->id,
                ]);

                $summary['updated']++;
                $summary['statuses'][$record['status']] = ($summary['statuses'][$record['status']] ?? 0) + 1;
            }
        }

        $unit->syncProgressFromTargets();

        return $summary;
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

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rowKey = $this->rowKey($item, $settings);
            $status = $statuses->resolve($this->firstField($item, self::STATUS_FIELDS));

            if ($rowKey === null || $status === null) {
                continue;
            }

            $doneAt = $this->firstField($item, self::TIME_FIELDS);

            $records[$rowKey] = [
                'status' => $status,
                'reason' => $this->firstField($item, self::REASON_FIELDS),
                // Waktu dari script biasanya UTC ("…Z"): simpan dalam zona waktu aplikasi (WITA)
                'done_at' => $doneAt ? rescue(fn () => Carbon::parse($doneAt)->setTimezone(config('app.timezone')), null, false) : null,
                'result' => $this->result($item),
            ];
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
            ? TargetImporter::sheetRowKey((string) ($item['sheet'] ?? 'Sheet1'), (int) $row)
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
        $skip = [...self::STATUS_FIELDS, ...self::REASON_FIELDS, ...self::TIME_FIELDS, 'values', 'candidates'];
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
