<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\ProjectSettings;
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
     * @return array{sheets: int, rows: int, tracked: int, kept: int}
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

        $summary = ['sheets' => 0, 'rows' => 0, 'tracked' => 0, 'kept' => 0];

        DB::transaction(function () use ($sheets, $kecamatan, $file, $settings, $previous, &$summary) {
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

                $keyColumn = $settings->keyColumn() !== null ? $targetSheet->columnIndex($settings->keyColumn()) : null;
                $targetSheet->tracked = count($sheet['rows']) > 0
                    && ($settings->keyMode() === ProjectSettings::KEY_ROW || $keyColumn !== null);
                $targetSheet->save();

                $records = [];

                foreach ($sheet['rows'] as $rowNumber => $cells) {
                    $rowKey = match (true) {
                        ! $targetSheet->tracked => null,
                        $settings->keyMode() === ProjectSettings::KEY_ROW => self::sheetRowKey($sheet['name'], $rowNumber),
                        default => self::normalizeKey($cells[$keyColumn] ?? null),
                    };

                    $state = ['status' => null, 'reason' => null, 'result' => null, 'status_at' => null, 'status_file_id' => null];

                    if ($rowKey && $previous->has($rowKey)) {
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
                        'cells' => json_encode($cells, JSON_UNESCAPED_UNICODE),
                        ...$state,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $summary['rows']++;
                    $summary['tracked'] += $rowKey ? 1 : 0;

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
     * Hitung ulang kunci baris dari isi baris yang sudah tersimpan, sesuai pengaturan sekarang
     * (mis. setelah cara pencocokan diganti dari nomor baris ke kolom link). Tidak butuh file Excel.
     *
     * @return int jumlah baris yang kuncinya berubah
     */
    public function rekey(Kecamatan $kecamatan): int
    {
        $settings = $kecamatan->project->config();
        $changed = 0;

        foreach ($kecamatan->targetSheets()->get() as $sheet) {
            $keyColumn = $settings->keyColumn() !== null ? $sheet->columnIndex($settings->keyColumn()) : null;
            $sheet->update([
                'tracked' => $sheet->row_count > 0 && ($settings->keyMode() === ProjectSettings::KEY_ROW || $keyColumn !== null),
            ]);

            TargetRow::where('target_sheet_id', $sheet->id)
                ->select(['id', 'row_number', 'row_key', 'cells'])
                ->chunkById(500, function ($rows) use ($sheet, $settings, $keyColumn, &$changed) {
                    foreach ($rows as $row) {
                        $rowKey = match (true) {
                            ! $sheet->tracked => null,
                            $settings->keyMode() === ProjectSettings::KEY_ROW => self::sheetRowKey($sheet->name, $row->row_number),
                            default => self::normalizeKey($row->cells[$keyColumn] ?? null),
                        };

                        if ($rowKey !== $row->row_key) {
                            TargetRow::whereKey($row->id)->update(['row_key' => $rowKey]);
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

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headerValues = null;
                $rows = [];
                $rowNumber = 0;
                $width = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    // Sel rumus (mis. "=P2+Q2") diambil hasil hitungnya, bukan teks rumusnya.
                    // Sel kosong di ujung kanan dibuang: Excel yang pernah diformat satu baris penuh
                    // bisa terbaca sampai 16.384 kolom kosong.
                    $values = $this->trimTrailingEmpty(array_map(
                        fn (Cell $cell) => $this->normalizeCell($cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue()),
                        $row->cells,
                    ));

                    if ($values === []) {
                        continue;
                    }

                    $width = max($width, count($values));

                    if ($headerValues === null) {
                        $headerValues = $values;

                        continue;
                    }

                    $rows[$rowNumber] = $values;
                }

                $sheets[] = [
                    'name' => $sheet->getName(),
                    'headers' => $headerValues === null ? [] : $this->normalizeHeaders(array_pad($headerValues, $width, null)),
                    'rows' => array_map(fn (array $values) => array_pad($values, $width, null), $rows),
                ];
            }
        } finally {
            $reader->close();

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
