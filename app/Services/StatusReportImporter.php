<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\ProjectSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** Field kunci yang umum di laporan script, dicoba bila field kunci pengaturan tidak ada di laporan. */
    private const COMMON_KEY_FIELDS = ['id', 'assignment_id', 'url', 'link'];

    /** Penanda sheet untuk laporan yang hanya menyebut nomor baris; diganti sheet milik unit saat diterapkan. */
    private const ANY_SHEET = '*';

    /** Akhiran kunci untuk "semua baris dengan kunci ini" (laporan tanpa pembeda pada pekerjaan berkolom pembeda). */
    private const ANY_DETAIL = '#*';

    /**
     * Isi baris yang dibandingkan dengan Excel pada pencocokan nomor baris: field di laporan => nama kolom Excel.
     * Laporan dari file "belum selesai" memakai nomor baris file itu, jadi isinya yang menentukan baris aslinya.
     */
    private const CONTENT_FIELDS = [
        'krt' => ['report' => ['krt', 'nama_krt'], 'excel' => ['Nama Kepala Rumah Tangga']],
        'usaha' => ['report' => ['namaUsaha', 'nama_usaha', 'nama'], 'excel' => ['Nama Usaha', 'nama_usaha']],
    ];

    /**
     * Indeks isi baris pekerjaan (per kombinasi field) => [id, unit], dibuat sekali bila dibutuhkan.
     *
     * @var array<string, array<string, list<array{0: int, 1: int}>>>
     */
    private array $contentIndex = [];

    /**
     * Isi laporan yang sudah dibaca (per id file) beserta statistiknya, supaya satu laporan tidak dibaca
     * berulang saat banyak unit dihitung ulang sekaligus.
     *
     * @var array<int, array{records: array<string, mixed>, stats: array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}}>
     */
    private array $parsed = [];

    /** Field nama pembeda di laporan (mis. targets[].nama = Nama usaha), selain nama kolom pembeda itu sendiri. */
    private const DETAIL_NAME_FIELDS = ['nama', 'nama_usaha', 'namaUsaha'];

    /**
     * Statistik pembacaan terakhir, untuk menjelaskan kenapa laporan tidak cocok.
     *
     * @var array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}
     */
    private array $lastParse = ['items' => 0, 'without_key' => 0, 'unknown_statuses' => [], 'sample_keys' => []];

    /**
     * Terapkan laporan ke unit. Baris yang ternyata milik unit lain pekerjaan ini (kunci sama di unit lain, atau
     * isi baris cocok dengan baris unit lain) diteruskan ke unit pemiliknya.
     *
     * @return array{records: int, matched: int, updated: int, skipped: int, unmatched: int, forwarded: array<string, int>, statuses: array<string, int>, unit: string, parse: array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}}
     */
    public function import(ReportFile $file, Kecamatan $unit): array
    {
        $settings = $unit->project->config();
        $records = $this->records($file, $unit);
        $targets = $this->targetRows($records, $unit);

        $defaultCode = $settings->statuses()->defaultCode();
        $summary = ['records' => count($records), 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'unmatched' => 0, 'forwarded' => [], 'statuses' => [], 'unit' => $unit->nama, 'parse' => $this->lastParse];
        $touched = collect();

        foreach ($records as $rowKey => $record) {
            $rows = $targets[$rowKey];

            if ($rows->isEmpty()) {
                $summary['unmatched']++;

                continue;
            }

            if ($rows->contains('kecamatan_id', $unit->id)) {
                $summary['matched']++;
            }

            foreach ($rows as $row) {
                $applied = $this->apply($row, $record, $file, $defaultCode);

                if ($row->kecamatan_id !== $unit->id) {
                    if ($applied) {
                        $other = $row->kecamatan;
                        $summary['forwarded'][$other->nama] = ($summary['forwarded'][$other->nama] ?? 0) + 1;
                        $touched->put($other->id, $other);
                    }
                } elseif ($applied) {
                    $summary['updated']++;
                    $summary['statuses'][$record['status']] = ($summary['statuses'][$record['status']] ?? 0) + 1;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        $unit->syncProgressFromTargets();
        $touched->each(fn (Kecamatan $other) => $other->syncProgressFromTargets());
        $this->rememberForwards($file, $targets, $unit);

        return $summary;
    }

    /**
     * Terapkan ulang satu laporan ke baris milik $owner saja: laporan $owner sendiri, atau laporan unit lain yang
     * sebagian barisnya ternyata milik $owner (diteruskan). Dipakai saat status $owner dihitung ulang.
     */
    public function applyTo(ReportFile $file, Kecamatan $owner): int
    {
        if (! Storage::disk('local')->exists($file->path)) {
            return 0;
        }

        $home = $file->kecamatan ?? $owner;

        if ($home->id !== $owner->id && ! $file->forwardsTo($owner->id)) {
            return 0;
        }

        $records = $this->records($file, $home);
        $defaultCode = $owner->project->config()->statuses()->defaultCode();
        $applied = 0;
        $targets = $this->targetRows($records, $home);

        if ($file->forwarded_units === null) {
            $this->rememberForwards($file, $targets, $home);
        }

        foreach ($targets as $rowKey => $rows) {
            foreach ($rows->where('kecamatan_id', $owner->id) as $row) {
                $applied += $this->apply($row, $records[$rowKey], $file, $defaultCode) ? 1 : 0;
            }
        }

        if ($applied > 0) {
            $owner->syncProgressFromTargets();
        }

        return $applied;
    }

    /**
     * Simpan unit lain yang baris-barisnya disentuh laporan ini.
     *
     * @param  array<string, Collection<int, TargetRow>>  $targets
     */
    private function rememberForwards(ReportFile $file, array $targets, Kecamatan $home): void
    {
        $units = collect($targets)->flatten(1)->pluck('kecamatan_id')->reject(fn ($id) => $id === $home->id)->unique()->values()->all();

        $file->forceFill(['forwarded_units' => $units])->saveQuietly();
    }

    /**
     * Unit pekerjaan ini yang paling banyak memiliki baris laporan. Dipakai saat upload supaya laporan yang
     * terupload di halaman unit lain (nama file laporan sering sama, atau laporan dari file "belum selesai")
     * otomatis masuk ke unit pemiliknya. Null bila $unit sendiri sudah yang terbanyak atau tidak ada yang cocok.
     */
    public function ownerUnitId(ReportFile $file, Kecamatan $unit): ?int
    {
        $counts = collect($this->targetRows($this->records($file, $unit), $unit))
            ->flatten(1)
            ->countBy('kecamatan_id')
            ->sortDesc();

        $best = $counts->keys()->first();

        return $best !== null && (int) $best !== $unit->id && $counts[$best] > ($counts[$unit->id] ?? 0) ? (int) $best : null;
    }

    /**
     * Baris tujuan tiap kunci laporan yang diupload di unit $home.
     * - Kolom kunci: baris berkunci sama di $home; bila tidak ada, di unit lain pekerjaan ini.
     * - Nomor baris: baris di nomor itu asalkan isinya (KRT / nama usaha) cocok; bila tidak cocok, baris berisi
     *   sama di seluruh pekerjaan (unit $home didahulukan). Laporan tanpa isi pembanding hanya berlaku di $home.
     *
     * @param  array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>  $records
     * @return array<string, Collection<int, TargetRow>>
     */
    private function targetRows(array $records, Kecamatan $home): array
    {
        $keys = array_keys($records);
        $own = collect();

        foreach (array_chunk($keys, 1000) as $chunk) {
            $own = $own->merge(TargetRow::where('kecamatan_id', $home->id)->tracked()->whereIn('row_key', $chunk)->with(['sheet', 'kecamatan'])->get());
        }

        $own = $own->groupBy('row_key');

        if ($home->project->config()->keyMode() === ProjectSettings::KEY_COLUMN) {
            $missing = array_values(array_diff($keys, $own->keys()->all()));
            $elsewhere = collect();

            foreach (array_chunk($missing, 1000) as $chunk) {
                $elsewhere = $elsewhere->merge(TargetRow::where('project_id', $home->project_id)
                    ->where('kecamatan_id', '!=', $home->id)
                    ->tracked()
                    ->whereIn('row_key', $chunk)
                    ->with('kecamatan')
                    ->get());
            }

            $elsewhere = $elsewhere->groupBy('row_key');

            return array_combine($keys, array_map(fn (string $key) => $own->get($key) ?? $elsewhere->get($key) ?? collect(), $keys));
        }

        $targets = [];
        $used = [];
        $lookups = [];

        foreach ($records as $key => $record) {
            $row = $own->get($key)?->first();

            if ($row !== null && $this->contentMatches($row, $record['match']) !== false) {
                $targets[$key] = collect([$row]);
                $used[$row->id] = true;
            } elseif ($record['match'] !== []) {
                $lookups[$key] = $record['match'];
            } else {
                $targets[$key] = collect();
            }
        }

        $found = [];

        foreach ($lookups as $key => $match) {
            $found[$key] = $this->findByContent($home, $match, $used);
        }

        $rows = TargetRow::whereKey(array_filter($found))->with('kecamatan')->get()->keyBy('id');

        foreach ($lookups as $key => $match) {
            $targets[$key] = $found[$key] !== null && $rows->has($found[$key]) ? collect([$rows[$found[$key]]]) : collect();
        }

        return $targets + array_fill_keys($keys, collect());
    }

    /**
     * Id baris pekerjaan yang isinya sama (belum dipakai kunci laporan lain), unit $home didahulukan.
     *
     * @param  array<string, string>  $match
     * @param  array<int, bool>  $used
     */
    private function findByContent(Kecamatan $home, array $match, array &$used): ?int
    {
        $signature = implode(',', array_keys($match));
        $index = $this->contentIndex[$home->project_id.':'.$signature] ??= $this->buildContentIndex($home->project_id, array_keys($match));
        $candidates = $index[implode('|', $match)] ?? [];

        usort($candidates, fn (array $a, array $b) => ($b[1] === $home->id) <=> ($a[1] === $home->id));

        foreach ($candidates as [$id]) {
            if (! isset($used[$id])) {
                $used[$id] = true;

                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function buildContentIndex(int $projectId, array $fields): array
    {
        $index = [];
        $sheets = [];

        TargetRow::where('project_id', $projectId)
            ->tracked()
            ->select(['id', 'kecamatan_id', 'target_sheet_id', 'row_number', 'cells'])
            ->orderBy('kecamatan_id')
            ->orderBy('row_number')
            ->lazy(1000)
            ->each(function (TargetRow $row) use ($fields, &$index, &$sheets) {
                $sheet = $sheets[$row->target_sheet_id] ??= TargetSheet::find($row->target_sheet_id);
                $content = $sheet ? $this->rowContent($row, $sheet, $fields) : [];

                if (count($content) === count($fields)) {
                    $index[implode('|', $content)][] = [$row->id, $row->kecamatan_id];
                }
            });

        return $index;
    }

    /**
     * Apakah isi baris sama dengan isi laporan; null bila tidak ada yang bisa dibandingkan.
     *
     * @param  array<string, string>  $match
     */
    private function contentMatches(TargetRow $row, array $match): ?bool
    {
        if ($match === [] || $row->sheet === null) {
            return null;
        }

        $content = $this->rowContent($row, $row->sheet, array_keys($match));
        $compared = array_intersect_key($match, $content);

        return $compared === [] ? null : $compared == array_intersect_key($content, $compared);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string> isi kolom Excel yang ada di sheet ini (dinormalkan)
     */
    private function rowContent(TargetRow $row, TargetSheet $sheet, array $fields): array
    {
        $content = [];

        foreach ($fields as $field) {
            foreach (self::CONTENT_FIELDS[$field]['excel'] as $header) {
                if (($index = $sheet->columnIndex($header)) !== null) {
                    $content[$field] = self::normalizeContent($row->cells[$index] ?? '');

                    break;
                }
            }
        }

        return $content;
    }

    /**
     * Isi pembanding dari laporan (item + target), mis. ['krt' => 'FAUZI', 'usaha' => 'JUAL PULSA'].
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    private function contentOf(array $fields): array
    {
        $excel = is_array($fields['excel'] ?? null) ? $fields['excel'] : [];
        $content = [];

        foreach (self::CONTENT_FIELDS as $name => $spec) {
            $value = $this->firstField($fields, $spec['report']);

            foreach ($excel as $header => $cell) {
                if ($value === null && is_scalar($cell) && in_array(TargetSheet::normalizeHeader((string) $header), array_map(TargetSheet::normalizeHeader(...), $spec['excel']), true)) {
                    $value = (string) $cell;
                }
            }

            if ($value !== null && ($value = self::normalizeContent($value)) !== '') {
                $content[$name] = $value;
            }
        }

        return $content;
    }

    private static function normalizeContent(mixed $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper((string) $value)));
    }

    /**
     * Isi laporan siap dicocokkan ke baris $unit: sheet & pembeda yang tidak disebut laporan dilengkapi.
     *
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>
     */
    private function records(ReportFile $file, Kecamatan $unit): array
    {
        if (! isset($this->parsed[$file->id])) {
            $records = $this->parse(Storage::disk('local')->get($file->path), $file->extension, $unit->project->config());
            $this->parsed[$file->id] = ['records' => $records, 'stats' => $this->lastParse];
        }

        $records = $this->parsed[$file->id]['records'];
        $this->lastParse = $this->parsed[$file->id]['stats'];

        return $this->expandAnyDetail($this->forUnitSheet($records, $unit), $unit->project_id);
    }

    /**
     * Kunci "uuid#*" (laporan tanpa nama usaha) berlaku untuk semua baris dengan kunci itu, mis. "uuid#a1b2…".
     *
     * @param  array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>  $records
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>
     */
    private function expandAnyDetail(array $records, int $projectId): array
    {
        foreach (array_keys($records) as $key) {
            if (! str_ends_with($key, self::ANY_DETAIL)) {
                continue;
            }

            $record = $records[$key];
            unset($records[$key]);

            TargetRow::where('project_id', $projectId)
                ->where('row_key', 'like', substr($key, 0, -1).'%')
                ->distinct()
                ->pluck('row_key')
                ->each(function (string $rowKey) use (&$records, $record) {
                    $records[$rowKey] ??= $record;
                });
        }

        return $records;
    }

    /**
     * Kunci "*!4" (laporan tanpa nama sheet) memakai sheet file unit, mis. "bagian 1!4".
     *
     * @param  array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>  $records
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>
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
     * @param  array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}  $record
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
            'status_at' => $record['done_at'] ?? $file->created_at ?? now(),
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
     * @return array<string, array{status: string, reason: ?string, done_at: ?Carbon, result: array<string, scalar>, match: array<string, string>}>
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

                $records[$rowKey] = [
                    ...$record,
                    'result' => $target === [] ? $record['result'] : array_slice([...$record['result'], ...$target], 0, self::MAX_RESULT_FIELDS, true),
                    'match' => $this->contentOf([...$item, ...$target]),
                ];
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

        if ($key !== null && ($detailColumn = $settings->keyDetailColumn()) !== null) {
            return $this->detailKeys($item, $key, $detailColumn, $settings);
        }

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
     * Kunci gabungan kunci + pembeda: satu per targets[] (mis. tiap usaha dalam satu assignment), atau dari
     * field pembeda di item itu sendiri; tanpa pembeda sama sekali berlaku untuk semua baris kunci itu.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, array<string, scalar>>
     */
    private function detailKeys(array $item, string $key, string $detailColumn, ProjectSettings $settings): array
    {
        // Kunci gabungan yang sudah jadi (mis. dari "Download JSON terkini") dipakai apa adanya
        $raw = (string) $this->firstField($item, $settings->reportKeyFields());

        if (str_contains($raw, '#')) {
            return [strtolower(strstr($raw, '#', true)).strstr($raw, '#') => []];
        }

        $fields = [$detailColumn, ...self::DETAIL_NAME_FIELDS];
        $keys = [];

        foreach (is_array($item['targets'] ?? null) ? $item['targets'] : [] as $target) {
            if (is_array($target) && ($detail = $this->firstField($target, $fields)) !== null) {
                $keys[TargetImporter::detailKey($key, $detail)] = array_filter(
                    array_diff_key($target, ['row' => 0, 'sheet' => 0, 'status' => 0]),
                    fn ($value) => is_scalar($value) && $value !== '',
                );
            }
        }

        if ($keys !== []) {
            return $keys;
        }

        $detail = $this->firstField($item, $fields) ?? $this->firstField(is_array($item['excel'] ?? null) ? $item['excel'] : [], [$detailColumn]);

        return [$detail !== null ? TargetImporter::detailKey($key, $detail) : $key.self::ANY_DETAIL => []];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rowKey(array $item, ProjectSettings $settings): ?string
    {
        if ($settings->keyMode() === ProjectSettings::KEY_COLUMN) {
            $key = TargetImporter::normalizeKey($this->firstField($item, $settings->reportKeyFields()));

            if ($key !== null) {
                return $key;
            }

            // Field kunci pengaturan tidak ada di laporan (mis. diisi "bku_link" padahal script memakai "id"):
            // coba field umum, asal isinya UUID supaya tidak salah cocok
            foreach (self::COMMON_KEY_FIELDS as $field) {
                $value = (string) $this->firstField($item, [$field]);

                if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value)) {
                    return TargetImporter::normalizeKey($value);
                }
            }

            return null;
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
