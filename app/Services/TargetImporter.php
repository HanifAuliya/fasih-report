<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\ProjectSettings;
use App\Support\XlsxPackage;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/**
 * Impor Excel menjadi sheet & baris di database, mengikuti pengaturan data (kolom kunci / nomor baris).
 * Status yang sudah ada dipertahankan berdasarkan kunci baris, jadi Excel boleh diupload ulang.
 */
class TargetImporter
{
    private const CHUNK = 500;

    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    /**
     * @return array{sheets: int, rows: int, tracked: int, kept: int, not_ready: int, duplicates: array<int, int>}
     */
    public function import(ReportFile $file, Kecamatan $kecamatan): array
    {
        $sheets = $this->readWorkbook(Storage::disk('local')->path($file->path));
        $settings = $kecamatan->project->config();

        $previous = TargetRow::where('kecamatan_id', $kecamatan->id)
            ->tracked()
            ->whereNotNull('status')
            ->get(['row_key', 'status', 'reason', 'result', 'status_at', 'status_file_id'])
            ->keyBy('row_key');

        $owners = $this->ownersElsewhere($kecamatan);
        $summary = ['sheets' => 0, 'rows' => 0, 'tracked' => 0, 'kept' => 0, 'not_ready' => 0, 'duplicates' => []];

        $hasDataSheet = collect($sheets)->contains(fn (array $sheet) => $this->hasKnownColumns(new TargetSheet(['headers' => $sheet['headers']]), $settings));

        DB::transaction(function () use ($sheets, $kecamatan, $file, $settings, $previous, $owners, $hasDataSheet, &$summary) {
            $kecamatan->targetSheets()->delete();
            $now = now();

            foreach ($sheets as $position => $sheet) {
                $targetSheet = $kecamatan->targetSheets()->make([
                    'report_file_id' => $file->id,
                    'name' => $sheet['name'],
                    'position' => $position,
                    'headers' => $sheet['headers'],
                    'row_count' => count($sheet['rows']),
                ]);

                $columns = $this->matchingColumns($targetSheet, $settings);
                $targetSheet->tracked = $this->isTrackable($targetSheet, $settings, $columns, $hasDataSheet);
                $targetSheet->save();

                $records = [];

                foreach ($sheet['rows'] as $rowNumber => $cells) {
                    [$rowKey, $notReady] = $this->rowKeyFor($targetSheet, $cells, $rowNumber, $settings, $columns);

                    $state = ['status' => null, 'reason' => null, 'result' => null, 'status_at' => null, 'status_file_id' => null];
                    // Kunci sudah dimiliki unit lain (mis. sudah ada di Bagian sebelumnya): tampil, tidak dihitung
                    $duplicateOf = $rowKey !== null ? ($owners[$rowKey] ?? null) : null;

                    if ($duplicateOf !== null) {
                        $summary['duplicates'][$duplicateOf] = ($summary['duplicates'][$duplicateOf] ?? 0) + 1;
                    } elseif ($rowKey && $previous->has($rowKey)) {
                        $old = $previous[$rowKey];
                        $state = [
                            'status' => $old->status,
                            'reason' => $old->reason,
                            'result' => $old->result ? json_encode($old->result) : null,
                            'status_at' => $old->status_at,
                            'status_file_id' => $old->status_file_id,
                        ];
                        $summary['kept']++;
                    } elseif ($rowKey) {
                        [$state['status'], $state['reason']] = $this->initialStatus($targetSheet, $cells, $settings);
                    }

                    $records[] = [
                        'project_id' => $kecamatan->project_id,
                        'kecamatan_id' => $kecamatan->id,
                        'target_sheet_id' => $targetSheet->id,
                        'row_number' => $rowNumber,
                        'row_key' => $rowKey,
                        'duplicate_of_id' => $duplicateOf,
                        'not_ready' => $notReady,
                        'cells' => json_encode($cells, JSON_UNESCAPED_UNICODE),
                        ...$state,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $summary['rows']++;
                    $summary['tracked'] += $rowKey && $duplicateOf === null ? 1 : 0;
                    $summary['not_ready'] += $notReady ? 1 : 0;

                    if (count($records) >= self::CHUNK) {
                        TargetRow::insert($records);
                        $records = [];
                    }
                }

                if ($records) {
                    TargetRow::insert($records);
                }

                $summary['sheets']++;
            }
        });

        $kecamatan->syncProgressFromTargets();

        return $summary;
    }

    /**
     * Posisi kolom yang dipakai untuk menentukan kunci baris di satu sheet.
     *
     * @return array{key: ?int, task: ?int, required: array<string, int>}
     */
    private function matchingColumns(TargetSheet $sheet, ProjectSettings $settings): array
    {
        $required = [];

        foreach ($settings->requiredColumns() as $name) {
            if (($index = $sheet->columnIndex($name)) !== null) {
                $required[$name] = $index;
            }
        }

        return [
            'key' => $this->keyColumnIndex($sheet, $settings),
            'task' => $settings->taskColumn() !== null ? $sheet->columnIndex($settings->taskColumn()) : null,
            'required' => $required,
        ];
    }

    /**
     * Kolom kunci sesuai pengaturan; bila tidak ada di file ini, coba nama kolom kunci yang umum
     * (mis. pengaturan "link" tapi file memakai "assignment_id" / "link_fasih").
     */
    private function keyColumnIndex(TargetSheet $sheet, ProjectSettings $settings): ?int
    {
        if ($settings->keyMode() !== ProjectSettings::KEY_COLUMN) {
            return null;
        }

        foreach (array_filter([$settings->keyColumn(), ...ProjectSettings::COMMON_KEY_COLUMNS]) as $name) {
            if (($index = $sheet->columnIndex($name)) !== null) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Kunci baris (null = tidak dihitung) & apakah baris "belum siap" (dikerjakan tapi kolom wajib kosong).
     *
     * @param  list<mixed>  $cells
     * @param  array{key: ?int, task: ?int, required: array<string, int>}  $columns
     * @return array{0: ?string, 1: bool}
     */
    private function rowKeyFor(TargetSheet $sheet, array $cells, int $rowNumber, ProjectSettings $settings, array $columns): array
    {
        if (! $sheet->tracked) {
            return [null, false];
        }

        // Bukan baris yang perlu dikerjakan (mis. Edit KBLI ≠ 1): tampil, tidak dihitung
        if ($columns['task'] !== null && ! $settings->isTaskValue($cells[$columns['task']] ?? null)) {
            return [null, false];
        }

        // Perlu dikerjakan tapi isian wajib kosong (mis. KBLI Baru): belum siap, belum dihitung
        foreach ($columns['required'] as $index) {
            if (trim((string) ($cells[$index] ?? '')) === '') {
                return [null, true];
            }
        }

        return [
            $settings->keyMode() === ProjectSettings::KEY_ROW
                ? self::sheetRowKey($sheet->name, $rowNumber)
                : self::normalizeKey($cells[$columns['key']] ?? null),
            false,
        ];
    }

    /**
     * Kunci yang sudah dihitung di unit lain pekerjaan ini => id unit pemiliknya.
     * Hanya untuk pencocokan lewat kolom kunci (kunci "Sheet1!4" berbeda arti di tiap file).
     *
     * @return array<string, int>
     */
    private function ownersElsewhere(Kecamatan $kecamatan): array
    {
        if ($kecamatan->project->config()->keyMode() !== ProjectSettings::KEY_COLUMN) {
            return [];
        }

        return TargetRow::where('project_id', $kecamatan->project_id)
            ->where('kecamatan_id', '!=', $kecamatan->id)
            ->tracked()
            ->pluck('kecamatan_id', 'row_key')
            ->all();
    }

    /**
     * Sheet dilacak bila berisi baris dan bisa diberi kunci. Pada mode nomor baris, sheet tanpa satu pun kolom
     * yang dikenal pengaturan (mis. sheet "Rekap" berisi ringkasan) dilewati, selama file ini punya sheet data lain.
     *
     * @param  array{key: ?int}  $columns
     */
    private function isTrackable(TargetSheet $sheet, ProjectSettings $settings, array $columns, bool $hasDataSheet): bool
    {
        if ((int) $sheet->row_count === 0) {
            return false;
        }

        if ($settings->keyMode() === ProjectSettings::KEY_COLUMN) {
            return $columns['key'] !== null;
        }

        return ! $hasDataSheet || $this->hasKnownColumns($sheet, $settings);
    }

    private function hasKnownColumns(TargetSheet $sheet, ProjectSettings $settings): bool
    {
        $known = array_filter([$settings->recapColumn(), $settings->initialStatusColumn(), ...$settings->displayColumns()]);

        foreach ($known as $name) {
            if ($sheet->columnIndex($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hitung ulang kunci baris dari isi baris yang sudah tersimpan, sesuai pengaturan sekarang
     * (mis. setelah cara pencocokan diganti dari nomor baris ke kolom link). Tidak butuh file Excel.
     *
     * @return int jumlah baris yang kuncinya berubah
     */
    public function rekey(Kecamatan $kecamatan): int
    {
        $settings = $kecamatan->project->config();
        $owners = $this->ownersElsewhere($kecamatan);
        $changed = 0;

        $sheets = $kecamatan->targetSheets()->get();
        $hasDataSheet = $sheets->contains(fn (TargetSheet $sheet) => $this->hasKnownColumns($sheet, $settings));

        foreach ($sheets as $sheet) {
            $columns = $this->matchingColumns($sheet, $settings);
            $sheet->update(['tracked' => $this->isTrackable($sheet, $settings, $columns, $hasDataSheet)]);

            TargetRow::where('target_sheet_id', $sheet->id)
                ->select(['id', 'row_number', 'row_key', 'duplicate_of_id', 'not_ready', 'cells'])
                ->chunkById(500, function ($rows) use ($sheet, $settings, $columns, $owners, &$changed) {
                    foreach ($rows as $row) {
                        [$rowKey, $notReady] = $this->rowKeyFor($sheet, $row->cells, $row->row_number, $settings, $columns);
                        $duplicateOf = $rowKey !== null ? ($owners[$rowKey] ?? null) : null;

                        if ($rowKey !== $row->row_key || $duplicateOf !== $row->duplicate_of_id || $notReady !== $row->not_ready) {
                            // Baris yang tidak lagi dilacak (atau jadi kembar) tidak membawa status lama
                            TargetRow::whereKey($row->id)->update($rowKey === null || $duplicateOf !== null
                                ? ['row_key' => $rowKey, 'duplicate_of_id' => $duplicateOf, 'not_ready' => $notReady, 'status' => null, 'reason' => null, 'status_at' => null, 'status_file_id' => null]
                                : ['row_key' => $rowKey, 'duplicate_of_id' => null, 'not_ready' => false]);
                            $changed++;
                        }
                    }
                });
        }

        return $changed;
    }

    /**
     * Kembalikan status semua baris unit ke status awal dari Excel (sebelum laporan apa pun diterapkan).
     */
    public function resetStatuses(Kecamatan $kecamatan): void
    {
        $settings = $kecamatan->project->config();
        $sheets = $kecamatan->targetSheets()->where('tracked', true)->get()->keyBy('id');
        $groups = [];

        $kecamatan->targetRows()->tracked()->select(['id', 'target_sheet_id', 'cells'])->chunkById(500, function ($rows) use ($sheets, $settings, &$groups) {
            foreach ($rows as $row) {
                [$status, $reason] = $this->initialStatus($sheets[$row->target_sheet_id], $row->cells, $settings);
                $groups[$status."\0".$reason][] = $row->id;
            }
        });

        foreach ($groups as $group => $ids) {
            [$status, $reason] = explode("\0", $group, 2);

            foreach (array_chunk($ids, 500) as $chunk) {
                TargetRow::whereIn('id', $chunk)->update([
                    'status' => $status,
                    'reason' => $reason !== '' ? $reason : null,
                    'result' => null,
                    'status_at' => null,
                    'status_file_id' => null,
                ]);
            }
        }

        $kecamatan->syncProgressFromTargets();
    }

    /**
     * Kunci baris per nomor baris, sama dengan id antrean userscript FASIH Otomatis ("Sheet1!4").
     */
    public static function sheetRowKey(string $sheet, int $rowNumber): string
    {
        return strtolower($sheet.'!'.$rowNumber);
    }

    /**
     * Kunci dari isi kolom: kalau berisi UUID (mis. link FASIH atau <a href>), pakai UUID terakhir.
     */
    public static function normalizeKey(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match_all(self::UUID_PATTERN, $value, $matches)) {
            return strtolower(end($matches[0]));
        }

        return mb_strtolower(mb_substr($value, 0, 64));
    }

    /**
     * Status awal dari kolom yang diatur (mis. status_awal "dipindah", Catatan FASIH "KUNING: …").
     *
     * @param  list<mixed>  $cells
     * @return array{0: ?string, 1: ?string}
     */
    private function initialStatus(TargetSheet $sheet, array $cells, ProjectSettings $settings): array
    {
        $statuses = $settings->statuses();
        $column = $settings->initialStatusColumn() !== null ? $sheet->columnIndex($settings->initialStatusColumn()) : null;
        $value = $column !== null ? trim((string) ($cells[$column] ?? '')) : '';

        if ($value !== '' && ($code = $statuses->resolve($value)) && $code !== $statuses->defaultCode()) {
            $reason = str_contains($value, ':') ? trim(explode(':', $value, 2)[1]) : null;

            return [$code, $reason ?: null];
        }

        return [$statuses->defaultCode(), null];
    }

    /**
     * Baca semua sheet: baris pertama yang tidak kosong dianggap header.
     *
     * @return list<array{name: string, headers: list<string>, rows: array<int, list<mixed>>}>
     */
    public function readWorkbook(string $path): array
    {
        // Wajib dicek dulu: ZipArchive menghapus file kosong/bukan-zip saat ditutup.
        if (file_get_contents($path, length: 4) !== "PK\x03\x04") {
            throw new InvalidArgumentException('Bukan file .xlsx yang valid.');
        }

        $readPath = $this->withoutOversizedDimensions($path);
        // Baris kosong ikut dihitung supaya nomor baris = nomor baris asli di Excel
        // (dipakai kunci "Sheet1!4" dan untuk menulis status ke file Excel asli)
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($readPath);

        $sheets = [];
        $package = new ZipArchive;
        $sheetPaths = $package->open($path, ZipArchive::RDONLY) === true ? XlsxPackage::sheetPaths($package) : [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $links = isset($sheetPaths[$sheet->getName()]) ? XlsxPackage::hyperlinks($package, $sheetPaths[$sheet->getName()]) : [];
                $linksByRow = [];

                foreach ($links as $ref => $url) {
                    preg_match('/^([A-Z]+)(\d+)$/', $ref, $cell);
                    $linksByRow[(int) $cell[2]][XlsxPackage::columnIndex($cell[1])] = $url;
                }

                $rows = [];
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    // Sel rumus (mis. "=P2+Q2") diambil hasil hitungnya, bukan teks rumusnya.
                    $values = array_map(
                        fn (Cell $cell) => $this->normalizeCell($cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue()),
                        $row->cells,
                    );

                    // Sel ber-hyperlink (teksnya sering cuma "Link"): ambil alamat URL-nya
                    foreach ($linksByRow[$rowNumber] ?? [] as $column => $url) {
                        $values = array_pad($values, $column + 1, null);
                        $values[$column] = $this->hyperlinkValue($values[$column], $url);
                    }

                    // Sel kosong di ujung kanan dibuang: Excel yang pernah diformat satu baris penuh
                    // bisa terbaca sampai 16.384 kolom kosong.
                    $values = $this->trimTrailingEmpty($values);

                    if ($values !== []) {
                        $rows[$rowNumber] = $values;
                    }
                }

                // Baris judul laporan di atas tabel (mis. "DATA MIKRO …", "Wilayah: …") dilewati:
                // header = baris pertama yang isinya selebar tabel.
                $headerRow = $this->headerRowNumber($rows);
                $headerValues = $headerRow !== null ? $rows[$headerRow] : null;
                $rows = array_filter($rows, fn (int $number) => $headerRow !== null && $number > $headerRow, ARRAY_FILTER_USE_KEY);
                // Baris nomor kolom di bawah header ala tabel BPS: "(1)", "(2)", …
                $rows = array_filter($rows, fn (array $values) => ! $this->isColumnNumberRow($values));
                $width = max(count($headerValues ?? []), ...array_map('count', $rows ?: [[]]));

                $sheets[] = [
                    'name' => $sheet->getName(),
                    'headers' => $headerValues === null ? [] : $this->normalizeHeaders(array_pad($headerValues, $width, null)),
                    'rows' => array_map(fn (array $values) => array_pad($values, $width, null), $rows),
                ];
            }
        } finally {
            $reader->close();

            if ($sheetPaths !== []) {
                $package->close();
            }

            if ($readPath !== $path) {
                @unlink($readPath);
            }
        }

        return $sheets;
    }

    /**
     * Excel yang pernah diformat satu baris penuh mencatat ukuran sheet sampai kolom XFD (16.384 kolom),
     * dan OpenSpout lalu mengisi setiap baris dengan sel kosong sebanyak itu (sangat lambat).
     * Bila ada ukuran sheet ≥ 703 kolom (AAA), baca dari salinan tanpa info <dimension>, spans baris,
     * dan sel kosong berformat.
     */
    private function withoutOversizedDimensions(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $path;
        }

        $oversized = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $stream = preg_match('#^xl/worksheets/[^/]+\.xml$#', $name) === 1 ? $zip->getStream($name) : false;

            if ($stream === false) {
                continue;
            }

            $head = (string) fread($stream, 8192);
            fclose($stream);

            if (preg_match('/<dimension ref="[A-Z]+\d+:([A-Z]+)\d+"/', $head, $match) === 1 && strlen($match[1]) >= 3) {
                $oversized[] = $name;
            }
        }

        $zip->close();

        if ($oversized === []) {
            return $path;
        }

        $copy = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        copy($path, $copy);
        $zip->open($copy);

        foreach ($oversized as $name) {
            $xml = (string) $zip->getFromName($name);
            $xml = preg_replace('/<dimension [^>]*\/>/', '', $xml, 1);
            $xml = preg_replace('/(<row [^>]*?) spans="[^"]*"/', '$1', $xml);
            // Sel kosong yang hanya berformat (<c r="XFC1" s="3"/>) tidak berisi nilai apa pun
            $xml = preg_replace('/<c [^>]*\/>/', '', $xml);
            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return $copy;
    }

    /**
     * Baris header: di antara 10 baris terisi pertama, baris pertama yang jumlah sel terisinya
     * minimal separuh baris terlebar. Judul laporan (1 sel gabungan) jadi terlewati.
     *
     * @param  array<int, list<mixed>>  $rows
     */
    private function headerRowNumber(array $rows): ?int
    {
        $filled = array_map(
            fn (array $values) => count(array_filter($values, fn ($value) => $value !== null && $value !== '')),
            array_slice($rows, 0, 10, true),
        );

        if ($filled === []) {
            return null;
        }

        $needed = max(2, (int) ceil(max($filled) / 2));

        foreach ($filled as $number => $count) {
            if ($count >= $needed) {
                return $number;
            }
        }

        return array_key_first($filled);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isColumnNumberRow(array $values): bool
    {
        $filled = array_filter($values, fn ($value) => $value !== null && $value !== '');

        return count($filled) >= 2 && array_filter($filled, fn ($value) => ! preg_match('/^\(\d+\)$/', (string) $value)) === [];
    }

    /**
     * Teks sel ber-hyperlink: URL saja bila teksnya cuma "Link"/"Buka"/kosong,
     * selain itu teks dibungkus <a> (pola yang sama dengan Excel berisi rumus HYPERLINK HTML).
     */
    private function hyperlinkValue(mixed $text, string $url): string
    {
        $label = trim((string) $text);

        if ($label === '' || $label === $url || preg_match('/^(link|buka|klik( di ?sini)?|lihat|open|url)$/i', $label)) {
            return $url;
        }

        return '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">'.htmlspecialchars($label, ENT_QUOTES).'</a>';
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function trimTrailingEmpty(array $values): array
    {
        $values = array_values($values);
        $length = count($values);

        while ($length > 0 && ($values[$length - 1] === null || $values[$length - 1] === '')) {
            $length--;
        }

        return array_slice($values, 0, $length);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeHeaders(array $values): array
    {
        return array_values(array_map(
            fn ($value, $index) => trim((string) $value) !== '' ? trim((string) $value) : 'Kolom '.($index + 1),
            $values,
            array_keys($values),
        ));
    }

    private function normalizeCell(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_string($value) => trim($value),
            default => $value,
        };
    }
}
