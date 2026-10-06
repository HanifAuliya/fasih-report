<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use App\Support\XlsxPackage;
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

            $zip->addFromString('xl/workbook.xml', $this->filterDefinedNames((string) $zip->getFromName('xl/workbook.xml'), $filterRanges));

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
            ->get(['row_number', 'status', 'reason', 'status_at'])
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
            } elseif ($row = $rows->get($rowNumber)) {
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

            $cells = $this->setCells($match[2] ?? '', $rowNumber, $values, $firstStatusColumn, $styleFor);

            return $cells === null ? $match[0] : '<row'.preg_replace('/\sspans="[^"]*"/', '', $match[1]).'>'.$cells.'</row>';
        }, $xml) ?? throw new RuntimeException('Sheet terlalu besar untuk diproses: '.preg_last_error_msg());

        $lastColumn = $firstStatusColumn + count(self::STATUS_HEADERS) - 1;
        $range = 'A'.max(1, $headerRow).':'.XlsxPackage::columnLetter($lastColumn).max($headerRow, (int) $rows->keys()->max());

        return [$this->withAutoFilter($this->widenDimension($xml, $lastColumn), $range), $range];
    }

    /**
     * Pasang filter Excel (tombol ▾ di baris judul) di seluruh tabel, termasuk kolom status.
     * Filter yang sudah ada di file asli hanya diperluas range-nya.
     */
    private function withAutoFilter(string $xml, string $range): string
    {
        if (preg_match('/<autoFilter\b/', $xml)) {
            return preg_replace('/(<autoFilter\b[^>]*?\bref=")[^"]*(")/', '${1}'.$range.'${2}', $xml, 1);
        }

        // Urutan elemen xlsx: autoFilter setelah sheetData (dan sheetCalcPr/sheetProtection/protectedRanges/scenarios)
        if (! preg_match('/<\/sheetData>|<sheetData\s*\/>/', $xml, $match, PREG_OFFSET_CAPTURE)) {
            return $xml;
        }

        $position = $match[0][1] + strlen($match[0][0]);

        while (preg_match('/\G\s*<(sheetCalcPr|sheetProtection|protectedRanges|scenarios)\b(?:[^>]*\/>|.*?<\/\1>)/s', $xml, $next, 0, $position)) {
            $position += strlen($next[0]);
        }

        return substr($xml, 0, $position).'<autoFilter ref="'.$range.'"/>'.substr($xml, $position);
    }

    /**
     * Excel menyimpan range filter juga sebagai nama tersembunyi _xlnm._FilterDatabase per sheet.
     *
     * @param  array<string, string>  $ranges  nama sheet => range
     */
    private function filterDefinedNames(string $workbook, array $ranges): string
    {
        preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"/', $workbook, $sheets);
        $names = array_map(fn (string $name) => html_entity_decode($name, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $sheets[1]);

        foreach ($ranges as $sheetName => $range) {
            $index = array_search($sheetName, $names, true);

            if ($index === false) {
                continue;
            }

            [$from, $to] = explode(':', $range);
            $absolute = fn (string $cell) => preg_replace_callback('/^([A-Z]+)(\d+)$/', fn (array $m) => '$'.$m[1].'$'.$m[2], $cell);
            $reference = "'".str_replace("'", "''", $sheetName)."'!".$absolute($from).':'.$absolute($to);
            $definedName = '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$index.'" hidden="1">'
                .htmlspecialchars($reference, ENT_XML1, 'UTF-8').'</definedName>';

            $pattern = '/<definedName\b[^>]*name="_xlnm\._FilterDatabase"[^>]*localSheetId="'.$index.'"[^>]*>.*?<\/definedName>/s';

            // $ di referensi ($A$1) jangan dibaca sebagai grup regex saat disisipkan
            $replacement = addcslashes($definedName, '\\$');

            if (preg_match($pattern, $workbook)) {
                $workbook = preg_replace($pattern, $replacement, $workbook, 1);
            } elseif (str_contains($workbook, '</definedNames>')) {
                $workbook = str_replace('</definedNames>', $definedName.'</definedNames>', $workbook);
            } else {
                $workbook = preg_replace('/<\/sheets>/', '</sheets><definedNames>'.$replacement.'</definedNames>', $workbook, 1);
            }
        }

        return $workbook;
    }

    /**
     * Ganti/tambah sel di satu baris dengan urutan kolom tetap benar (syarat format xlsx).
     * Gaya (s="…") sel lama dipertahankan; kolom baru memakai gaya dari $styleFor
     * (dipanggil dengan gaya sel asli terakhir sebelum $firstNewColumn).
     *
     * @param  array<int, string|int>  $values  index kolom (0 = A) => nilai
     * @param  callable(?int): array<int, ?int>  $styleFor
     */
    private function setCells(string $inner, int $rowNumber, array $values, int $firstNewColumn, callable $styleFor): ?string
    {
        preg_match_all('/<c\b[^>]*?(?:\/>|>.*?<\/c>)/s', $inner, $matches);
        $cells = [];

        foreach ($matches[0] as $cell) {
            if (! preg_match('/\br="([A-Z]+)\d+"/', $cell, $ref)) {
                return null;
            }

            $cells[XlsxPackage::columnIndex($ref[1])] = $cell;
        }

        $baseColumn = collect(array_keys($cells))->filter(fn (int $column) => $column < $firstNewColumn)->max();
        $baseXf = $baseColumn !== null && preg_match('/\ss="(\d+)"/', $cells[$baseColumn], $s) ? (int) $s[1] : null;
        $newStyles = $styleFor($baseXf);

        foreach ($values as $column => $value) {
            $existing = $cells[$column] ?? '';
            $xf = array_key_exists($column, $newStyles)
                ? $newStyles[$column]
                : (preg_match('/\ss="(\d+)"/', $existing, $s) ? (int) $s[1] : null);
            $style = $xf ? ' s="'.$xf.'"' : '';
            $ref = XlsxPackage::columnLetter($column).$rowNumber;

            // Angka ditulis sebagai teks bila sel aslinya teks (mis. proses "1" -> "0")
            if (is_int($value) && preg_match('/\st="(s|str|inlineStr)"/', $existing)) {
                $value = (string) $value;
            }

            $cells[$column] = is_int($value)
                ? "<c r=\"{$ref}\"{$style}><v>{$value}</v></c>"
                : "<c r=\"{$ref}\"{$style} t=\"inlineStr\"><is><t xml:space=\"preserve\">".htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }

        ksort($cells);

        return implode('', $cells);
    }

    /**
     * Baris header = baris terakhir yang ada di sheet sebelum baris data pertama.
     */
    private function headerRow(string $xml, int $firstDataRow): int
    {
        preg_match_all('/<row\b[^>]*?\br="(\d+)"/', $xml, $numbers);

        return (int) collect($numbers[1])->map(fn ($n) => (int) $n)->filter(fn (int $n) => $n < $firstDataRow)->max();
    }

    private function widenDimension(string $xml, int $lastColumn): string
    {
        return preg_replace_callback('/<dimension ref="([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?"/', function (array $match) use ($lastColumn) {
            $endColumn = max(XlsxPackage::columnIndex($match[3] ?? $match[1]), $lastColumn);

            return '<dimension ref="'.$match[1].$match[2].':'.XlsxPackage::columnLetter($endColumn).($match[4] ?? $match[2]).'"';
        }, $xml, 1);
    }
}
