<?php

namespace App\Support;

use OpenSpout\Common\Entity\Style\Style;

/**
 * Tambah gaya sel baru ke styles.xml file Excel tanpa mengubah gaya yang sudah ada.
 * Dipakai untuk mewarnai sel status: gaya diturunkan dari sel asli di baris yang sama
 * (garis, perataan, format angka tetap), hanya latar & warna huruf yang diganti.
 */
class XlsxStyleBook
{
    /**
     * Warna Excel per warna status (latar muda + huruf tua, sama nuansanya dengan badge di web).
     *
     * @var array<string, array{fill: string, font: string}>
     */
    public const COLORS = [
        'slate' => ['fill' => 'F1F5F9', 'font' => '334155'],
        'blue' => ['fill' => 'DBEAFE', 'font' => '1D4ED8'],
        'violet' => ['fill' => 'EDE9FE', 'font' => '6D28D9'],
        'emerald' => ['fill' => 'D1FAE5', 'font' => '047857'],
        'teal' => ['fill' => 'CCFBF1', 'font' => '0F766E'],
        'cyan' => ['fill' => 'CFFAFE', 'font' => '0E7490'],
        'amber' => ['fill' => 'FEF3C7', 'font' => 'B45309'],
        'rose' => ['fill' => 'FFE4E6', 'font' => 'BE123C'],
        'fuchsia' => ['fill' => 'FAE8FF', 'font' => 'A21CAF'],
    ];

    /** @var array<string, Style> */
    private static array $openSpoutStyles = [];

    /** @var list<string> */
    private array $fonts = [];

    /** @var list<string> */
    private array $fills = [];

    /** @var list<string> */
    private array $cellXfs = [];

    private int $originalFonts;

    private int $originalFills;

    private int $originalXfs;

    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(private string $xml)
    {
        $this->fonts = $this->elements('fonts', 'font');
        $this->fills = $this->elements('fills', 'fill');
        $this->cellXfs = $this->elements('cellXfs', 'xf');
        $this->originalFonts = count($this->fonts);
        $this->originalFills = count($this->fills);
        $this->originalXfs = count($this->cellXfs);
    }

    /**
     * Gaya sel status berwarna untuk file yang dibangun dengan OpenSpout (export cadangan & rekap).
     */
    public static function openSpoutStyle(?string $color): ?Style
    {
        if ($color === null) {
            return null;
        }

        $colors = self::COLORS[$color] ?? self::COLORS['slate'];

        return self::$openSpoutStyles[$color] ??= (new Style)
            ->withBackgroundColor($colors['fill'])
            ->withFontColor($colors['font'])
            ->withFontBold(true);
    }

    /**
     * Index gaya (atribut s="…") untuk sel status berwarna, turunan dari gaya $baseXf.
     */
    public function statusStyle(?int $baseXf, string $color): ?int
    {
        $colors = self::COLORS[$color] ?? self::COLORS['slate'];
        $base = $this->cellXfs[$baseXf ?? 0] ?? $this->cellXfs[0] ?? null;

        if ($base === null || $this->fonts === [] || $this->fills === []) {
            return null;
        }

        $key = ($baseXf ?? 0).':'.$color;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $baseFont = $this->fonts[(int) $this->attribute($base, 'fontId')] ?? $this->fonts[0];
        $this->fonts[] = $this->boldColoredFont($baseFont, $colors['font']);
        $this->fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$colors['fill'].'"/><bgColor indexed="64"/></patternFill></fill>';

        $xf = $this->withAttribute($base, 'fontId', (string) (count($this->fonts) - 1));
        $xf = $this->withAttribute($xf, 'fillId', (string) (count($this->fills) - 1));
        $xf = $this->withAttribute($xf, 'applyFont', '1');
        $xf = $this->withAttribute($xf, 'applyFill', '1');
        $this->cellXfs[] = $xf;

        return $this->cache[$key] = count($this->cellXfs) - 1;
    }

    /**
     * styles.xml dengan gaya tambahan; sama persis dengan aslinya bila tidak ada yang ditambah.
     */
    public function toXml(): string
    {
        $xml = $this->xml;
        $xml = $this->append($xml, 'fonts', array_slice($this->fonts, $this->originalFonts), count($this->fonts));
        $xml = $this->append($xml, 'fills', array_slice($this->fills, $this->originalFills), count($this->fills));

        return $this->append($xml, 'cellXfs', array_slice($this->cellXfs, $this->originalXfs), count($this->cellXfs));
    }

    /**
     * @return list<string>
     */
    private function elements(string $container, string $element): array
    {
        if (! preg_match('/<'.$container.'\b[^>]*>(.*?)<\/'.$container.'>/s', $this->xml, $match)) {
            return [];
        }

        preg_match_all('/<'.$element.'\b(?:[^>]*\/>|[^>]*>.*?<\/'.$element.'>)/s', $match[1], $elements);

        return $elements[0];
    }

    /**
     * @param  list<string>  $added
     */
    private function append(string $xml, string $container, array $added, int $count): string
    {
        if ($added === []) {
            return $xml;
        }

        $xml = preg_replace('/(<'.$container.'\b[^>]*?\bcount=")\d+(")/', '${1}'.$count.'${2}', $xml, 1);

        return preg_replace_callback('/<\/'.$container.'>/', fn () => implode('', $added).'</'.$container.'>', $xml, 1);
    }

    /**
     * Font dasar (ukuran, nama, keluarga) dengan tebal & warna; urutan elemen mengikuti skema xlsx.
     */
    private function boldColoredFont(string $font, string $color): string
    {
        $part = fn (string $tag) => preg_match('/<'.$tag.'\b[^>]*\/>/', $font, $m) ? $m[0] : '';

        return '<font><b/>'.$part('i').$part('strike').$part('u').$part('vertAlign').$part('sz')
            .'<color rgb="FF'.$color.'"/>'.$part('name').$part('family').$part('charset').$part('scheme').'</font>';
    }

    private function attribute(string $element, string $name): ?string
    {
        return preg_match('/^<[a-zA-Z]+\b[^>]*?\b'.$name.'="([^"]*)"/', $element, $m) ? $m[1] : null;
    }

    private function withAttribute(string $element, string $name, string $value): string
    {
        if ($this->attribute($element, $name) !== null) {
            return preg_replace('/^(<[a-zA-Z]+\b[^>]*?\b'.$name.'=")[^"]*(")/', '${1}'.$value.'${2}', $element, 1);
        }

        return preg_replace('/^<([a-zA-Z]+)\b/', '<$1 '.$name.'="'.$value.'"', $element, 1);
    }
}
