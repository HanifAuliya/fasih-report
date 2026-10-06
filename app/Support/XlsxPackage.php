<?php

namespace App\Support;

use ZipArchive;

/**
 * Pembacaan bagian dalam file .xlsx (zip berisi XML) yang tidak disediakan OpenSpout:
 * letak XML tiap sheet, hyperlink sel, dan konversi huruf kolom.
 */
class XlsxPackage
{
    /**
     * Nama sheet => path XML-nya di dalam file (lewat workbook.xml & relasinya).
     *
     * @return array<string, string>
     */
    public static function sheetPaths(ZipArchive $zip): array
    {
        $targets = self::relationshipTargets((string) $zip->getFromName('xl/_rels/workbook.xml.rels'), 'xl/');
        $paths = [];

        preg_match_all('/<sheet\b[^>]*>/', (string) $zip->getFromName('xl/workbook.xml'), $sheets);

        foreach ($sheets[0] as $sheet) {
            if (preg_match('/\bname="([^"]+)"/', $sheet, $name) && preg_match('/\br:id="([^"]+)"/', $sheet, $id) && isset($targets[$id[1]])) {
                $paths[html_entity_decode($name[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')] = $targets[$id[1]];
            }
        }

        return $paths;
    }

    /**
     * Hyperlink eksternal di satu sheet: referensi sel (mis. "R6") => URL.
     *
     * @return array<string, string>
     */
    public static function hyperlinks(ZipArchive $zip, string $sheetPath): array
    {
        $xml = $zip->getFromName($sheetPath);

        if ($xml === false || ! str_contains($xml, '<hyperlink')) {
            return [];
        }

        $relsPath = dirname($sheetPath).'/_rels/'.basename($sheetPath).'.rels';
        $targets = self::relationshipTargets((string) $zip->getFromName($relsPath));
        $links = [];

        preg_match_all('/<hyperlink\b[^>]*>/', $xml, $hyperlinks);

        foreach ($hyperlinks[0] as $hyperlink) {
            if (preg_match('/\bref="([A-Z]+\d+)(?::[A-Z]+\d+)?"/', $hyperlink, $ref) && preg_match('/\br:id="([^"]+)"/', $hyperlink, $id) && isset($targets[$id[1]])) {
                $links[$ref[1]] = $targets[$id[1]];
            }
        }

        return $links;
    }

    /**
     * @return array<string, string> Id relasi => target (diawali $prefix untuk path relatif di dalam file)
     */
    private static function relationshipTargets(string $rels, ?string $prefix = null): array
    {
        $targets = [];
        preg_match_all('/<Relationship\b[^>]*>/', $rels, $relationships);

        foreach ($relationships[0] as $relationship) {
            if (preg_match('/\bId="([^"]+)"/', $relationship, $id) && preg_match('/\bTarget="([^"]+)"/', $relationship, $target)) {
                $value = html_entity_decode($target[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if ($prefix !== null) {
                    $value = ltrim($value, '/');
                    $value = str_starts_with($value, $prefix) ? $value : $prefix.$value;
                }

                $targets[$id[1]] = $value;
            }
        }

        return $targets;
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
