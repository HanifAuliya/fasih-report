<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use App\Support\XlsxPackage;
use App\Support\XlsxSheetEditor;
use App\Support\XlsxStyleBook;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Tulis status terbaru ke salinan file Excel asli yang dulu diupload, tanpa membangun ulang file:
 * format, warna, lebar kolom, rumus, dan sheet lain tetap utuh. Yang berubah hanya:
 * - kolom baru di ujung kanan sheet yang dilacak: status_web, keterangan_web, waktu_status_web;
 * - kolom "proses" = 0 untuk baris selesai & "status_awal" = dipindah (untuk userscript OSS).
 */
class OriginalWorkbookExporter
{
    public const STATUS_HEADERS = ['status_web', 'keterangan_web', 'waktu_status_web'];

    /**
     * @return string|null path file sementara, atau null bila file asli tidak tersedia
     */
    public function export(Kecamatan $unit): ?string
    {
        $sheets = $unit->targetSheets()->with('sourceFile')->get();
        $source = $sheets->pluck('sourceFile')->filter()->first();

        if (! $source || $sheets->contains(fn (TargetSheet $sheet) => $sheet->report_file_id !== $source->id)) {
            return null;
        }

        $original = Storage::disk('local')->path($source->path);

        if (! is_file($original) || file_get_contents($original, length: 4) !== "PK\x03\x04") {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        copy($original, $path);

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $statuses = $unit->project->config()->statuses();
        $sheetPaths = XlsxPackage::sheetPaths($zip);
        $filterRanges = [];
        $stylesXml = $zip->getFromName('xl/styles.xml');
        $styleBook = $stylesXml !== false ? new XlsxStyleBook($stylesXml) : null;

        // Format file tak terduga: batal, pemanggil memakai export biasa
        try {
            foreach ($sheets->filter(fn (TargetSheet $sheet) => $sheet->isTracked()) as $sheet) {
                $xmlPath = $sheetPaths[$sheet->name] ?? throw new RuntimeException("Sheet {$sheet->name} tidak ditemukan.");
                $xml = $zip->getFromName($xmlPath);

                if ($xml === false) {
                    throw new RuntimeException("Sheet {$sheet->name} tidak terbaca.");
                }

                [$xml, $filterRanges[$sheet->name]] = $this->writeStatuses($xml, $sheet, $statuses, $styleBook);
                $zip->addFromString($xmlPath, $xml);
            }

            if ($styleBook) {
                $zip->addFromString('xl/styles.xml', $styleBook->toXml());
            }

            $zip->addFromString('xl/workbook.xml', XlsxSheetEditor::filterDefinedNames((string) $zip->getFromName('xl/workbook.xml'), $filterRanges));

            $zip->close();
        } catch (Throwable $e) {
            report($e);
            $zip->close();
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * @return array{0: string, 1: string} XML sheet baru & range filter (mis. "A1:AF157")
     */
    private function writeStatuses(string $xml, TargetSheet $sheet, StatusSet $statuses, ?XlsxStyleBook $styleBook): array
    {
        $rows = TargetRow::where('target_sheet_id', $sheet->id)
            ->with('duplicateOf:id,nama')
            ->get(['row_number', 'status', 'reason', 'status_at', 'duplicate_of_id'])
            ->keyBy('row_number');

        $firstStatusColumn = count($sheet->headers);
        $lowerHeaders = array_map(fn ($header) => strtolower((string) $header), $sheet->headers);
        $processColumn = array_search('proses', $lowerHeaders, true);
        $initialStatusColumn = array_search('status_awal', $lowerHeaders, true);
        $headerRow = $this->headerRow($xml, (int) $rows->keys()->min());

        $xml = preg_replace_callback('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', function (array $match) use ($rows, $headerRow, $firstStatusColumn, $processColumn, $initialStatusColumn, $statuses, $styleBook) {
            if (! preg_match('/\br="(\d+)"/', $match[1], $number)) {
                return $match[0];
            }

            $rowNumber = (int) $number[1];
            $values = [];
            // Gaya kolom baru: ikut gaya sel asli terakhir di baris itu; sel status diberi warna status
            $statusColor = null;

            if ($rowNumber === $headerRow) {
                foreach (self::STATUS_HEADERS as $offset => $header) {
                    $values[$firstStatusColumn + $offset] = $header;
                }
            } elseif (($row = $rows->get($rowNumber)) && $row->duplicateOf) {
                // Baris kembar: dihitung di unit lain
                $statusColor = 'slate';
                $values = [$firstStatusColumn => 'Sudah di '.$row->duplicateOf->nama];
            } elseif ($row) {
                $statusColor = $row->status ? ($statuses->all()[$row->status]['color'] ?? 'slate') : null;
                $values = [
                    $firstStatusColumn => $row->status ? $statuses->label($row->status) : '',
                    $firstStatusColumn + 1 => (string) $row->reason,
                    $firstStatusColumn + 2 => $row->status_at?->format('Y-m-d H:i') ?? '',
                ];

                if ($processColumn !== false && $statuses->isDone($row->status)) {
                    $values[$processColumn] = 0;
                }

                if ($initialStatusColumn !== false && $statuses->hasAlias($row->status, 'dipindah')) {
                    $values[$initialStatusColumn] = 'dipindah';
                }
            }

            if ($values === []) {
                return $match[0];
            }

            $styleFor = function (?int $baseXf) use ($firstStatusColumn, $statusColor, $styleBook) {
                $styles = array_fill_keys(range($firstStatusColumn, $firstStatusColumn + count(self::STATUS_HEADERS) - 1), $baseXf);

                if ($statusColor && $styleBook) {
                    $styles[$firstStatusColumn] = $styleBook->statusStyle($baseXf, $statusColor) ?? $baseXf;
                }

                return $styles;
            };

            $cells = XlsxSheetEditor::setCells($match[2] ?? '', $rowNumber, $values, $firstStatusColumn, $styleFor);

            return $cells === null ? $match[0] : '<row'.preg_replace('/\sspans="[^"]*"/', '', $match[1]).'>'.$cells.'</row>';
        }, $xml) ?? throw new RuntimeException('Sheet terlalu besar untuk diproses: '.preg_last_error_msg());

        $lastColumn = $firstStatusColumn + count(self::STATUS_HEADERS) - 1;
        $range = 'A'.max(1, $headerRow).':'.XlsxPackage::columnLetter($lastColumn).max($headerRow, (int) $rows->keys()->max());

        return [XlsxSheetEditor::withAutoFilter(XlsxSheetEditor::widenDimension($xml, $lastColumn), $range), $range];
    }

    /**
     * Baris header = baris terakhir yang ada di sheet sebelum baris data pertama.
     */
    private function headerRow(string $xml, int $firstDataRow): int
    {
        preg_match_all('/<row\b[^>]*?\br="(\d+)"/', $xml, $numbers);

        return (int) collect($numbers[1])->map(fn ($n) => (int) $n)->filter(fn (int $n) => $n < $firstDataRow)->max();
    }
}
