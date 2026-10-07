<?php

namespace App\Support;

/**
 * Edit XML sheet .xlsx secara langsung (tanpa membangun ulang file), dipakai untuk menulis kolom status
 * ke file Excel asli: menambah sel di baris, memasang filter, dan memperlebar ukuran sheet.
 */
class XlsxSheetEditor
{
    /**
     * Pasang filter Excel (tombol ▾ di baris judul) di seluruh tabel, termasuk kolom status.
     * Filter yang sudah ada di file asli hanya diperluas range-nya.
     */
    public static function withAutoFilter(string $xml, string $range): string
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
    public static function filterDefinedNames(string $workbook, array $ranges): string
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

            // Urutan atribut bisa berbeda-beda (name/localSheetId/hidden)
            $pattern = '/<definedName\b(?=[^>]*\bname="_xlnm\._FilterDatabase")(?=[^>]*\blocalSheetId="'.$index.'")[^>]*>.*?<\/definedName>/s';

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
    public static function setCells(string $inner, int $rowNumber, array $values, int $firstNewColumn, callable $styleFor): ?string
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

    public static function widenDimension(string $xml, int $lastColumn): string
    {
        return preg_replace_callback('/<dimension ref="([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?"/', function (array $match) use ($lastColumn) {
            $endColumn = max(XlsxPackage::columnIndex($match[3] ?? $match[1]), $lastColumn);

            return '<dimension ref="'.$match[1].$match[2].':'.XlsxPackage::columnLetter($endColumn).($match[4] ?? $match[2]).'"';
        }, $xml, 1);
    }
}
