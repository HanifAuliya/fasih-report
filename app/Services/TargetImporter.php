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
use OpenSpout\Reader\XLSX\Reader;

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

        $reader = new Reader;
        $reader->open($path);

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rows = [];
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    // Sel rumus (mis. "=P2+Q2") diambil hasil hitungnya, bukan teks rumusnya
                    $values = array_map(
                        fn (Cell $cell) => $this->normalizeCell($cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue()),
                        $row->cells,
                    );

                    if ($headers === null) {
                        if (array_filter($values, fn ($value) => $value !== null && $value !== '') === []) {
                            continue;
                        }

                        $headers = $this->normalizeHeaders($values);

                        continue;
                    }

                    if (array_filter($values, fn ($value) => $value !== null && $value !== '') === []) {
                        continue;
                    }

                    $rows[$rowNumber] = array_slice(array_pad($values, count($headers), null), 0, count($headers));
                }

                $sheets[] = [
                    'name' => $sheet->getName(),
                    'headers' => $headers ?? [],
                    'rows' => $rows,
                ];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
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
