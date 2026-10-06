<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use Illuminate\Support\Collection;
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
        $sheetPaths = $this->sheetPaths($zip);

        // Format file tak terduga: batal, pemanggil memakai export biasa
        try {
            foreach ($sheets->filter(fn (TargetSheet $sheet) => $sheet->isTracked()) as $sheet) {
                $xmlPath = $sheetPaths[$sheet->name] ?? throw new RuntimeException("Sheet {$sheet->name} tidak ditemukan.");
                $xml = $zip->getFromName($xmlPath);

                if ($xml === false) {
                    throw new RuntimeException("Sheet {$sheet->name} tidak terbaca.");
                }

                $zip->addFromString($xmlPath, $this->writeStatuses($xml, $sheet, $statuses));
            }

            $zip->close();
        } catch (Throwable $e) {
            report($e);
            $zip->close();
            @unlink($path);

            return null;
        }

        return $path;
    }

    private function writeStatuses(string $xml, TargetSheet $sheet, StatusSet $statuses): string
    {
        $rows = TargetRow::where('target_sheet_id', $sheet->id)
            ->get(['row_number', 'status', 'reason', 'status_at'])
            ->keyBy('row_number');

        $firstStatusColumn = count($sheet->headers);
        $lowerHeaders = array_map(fn ($header) => strtolower((string) $header), $sheet->headers);
        $processColumn = array_search('proses', $lowerHeaders, true);
        $initialStatusColumn = array_search('status_awal', $lowerHeaders, true);
        $headerRow = $this->headerRow($xml, (int) $rows->keys()->min());

        $xml = preg_replace_callback('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', function (array $match) use ($rows, $headerRow, $firstStatusColumn, $processColumn, $initialStatusColumn, $statuses) {
            if (! preg_match('/\br="(\d+)"/', $match[1], $number)) {
                return $match[0];
            }

            $rowNumber = (int) $number[1];
            $values = [];

            if ($rowNumber === $headerRow) {
                foreach (self::STATUS_HEADERS as $offset => $header) {
                    $values[$firstStatusColumn + $offset] = $header;
                }
            } elseif ($row = $rows->get($rowNumber)) {
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

            $cells = $this->setCells($match[2] ?? '', $rowNumber, $values);

            return $cells === null ? $match[0] : '<row'.preg_replace('/\sspans="[^"]*"/', '', $match[1]).'>'.$cells.'</row>';
        }, $xml) ?? throw new RuntimeException('Sheet terlalu besar untuk diproses: '.preg_last_error_msg());

        return $this->widenDimension($xml, $firstStatusColumn + count(self::STATUS_HEADERS) - 1);
    }

    /**
     * Ganti/tambah sel di satu baris dengan urutan kolom tetap benar (syarat format xlsx).
     * Gaya (s="…") sel lama dipertahankan.
     *
     * @param  array<int, string|int>  $values  index kolom (0 = A) => nilai
     */
    private function setCells(string $inner, int $rowNumber, array $values): ?string
    {
        preg_match_all('/<c\b[^>]*?(?:\/>|>.*?<\/c>)/s', $inner, $matches);
        $cells = [];

        foreach ($matches[0] as $cell) {
            if (! preg_match('/\br="([A-Z]+)\d+"/', $cell, $ref)) {
                return null;
            }

            $cells[self::columnIndex($ref[1])] = $cell;
        }

        foreach ($values as $column => $value) {
            $existing = $cells[$column] ?? '';
            $style = preg_match('/\ss="(\d+)"/', $existing, $s) ? ' s="'.$s[1].'"' : '';
            $ref = self::columnLetter($column).$rowNumber;

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
            $endColumn = max(self::columnIndex($match[3] ?? $match[1]), $lastColumn);

            return '<dimension ref="'.$match[1].$match[2].':'.self::columnLetter($endColumn).($match[4] ?? $match[2]).'"';
        }, $xml, 1);
    }

    /**
     * Nama sheet => path XML-nya di dalam file (lewat workbook.xml & relasinya).
     *
     * @return array<string, string>
     */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');

        $targets = Collection::make();
        preg_match_all('/<Relationship\b[^>]*>/', $rels, $relationships);

        foreach ($relationships[0] as $relationship) {
            if (preg_match('/\bId="([^"]+)"/', $relationship, $id) && preg_match('/\bTarget="([^"]+)"/', $relationship, $target)) {
                $file = ltrim($target[1], '/');
                $targets[$id[1]] = str_starts_with($file, 'xl/') ? $file : 'xl/'.$file;
            }
        }

        $paths = [];
        preg_match_all('/<sheet\b[^>]*>/', $workbook, $sheets);

        foreach ($sheets[0] as $sheet) {
            if (preg_match('/\bname="([^"]+)"/', $sheet, $name) && preg_match('/\br:id="([^"]+)"/', $sheet, $id) && $targets->has($id[1])) {
                $paths[html_entity_decode($name[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')] = $targets[$id[1]];
            }
        }

        return $paths;
    }

    public static function columnLetter(int $index): string
    {
        $letter = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26).$letter;
        }

        return $letter;
    }

    public static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
