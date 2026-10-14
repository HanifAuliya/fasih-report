<?php

namespace App\Support;

/**
 * Pengaturan cara membaca Excel & laporan untuk satu manajemen data.
 */
class ProjectSettings
{
    /** Unit dicocokkan ke daftar kecamatan dari nama file (target_OSS_010_HARUYAN.xlsx). */
    public const UNIT_KECAMATAN = 'kecamatan';

    /** Setiap Excel jadi satu unit ("Bagian 01 …" atau nama file). */
    public const UNIT_FILE = 'file';

    /** Baris dicocokkan lewat isi kolom kunci (mis. assignment_id, link). */
    public const KEY_COLUMN = 'column';

    /** Baris dicocokkan lewat sheet + nomor baris Excel. */
    public const KEY_ROW = 'row';

    /** Nama kolom kunci yang umum di Excel FASIH, dicoba bila kolom kunci pengaturan tidak ada di file. */
    public const COMMON_KEY_COLUMNS = ['assignment_id', 'link_fasih', 'link'];

    public const DEFAULTS = [
        'unit_label' => 'Kecamatan',
        'unit_source' => self::UNIT_KECAMATAN,
        'key_mode' => self::KEY_COLUMN,
        'key_column' => 'assignment_id',
        'key_detail_column' => null,
        'report_key_fields' => ['assignment_id', 'id'],
        'recap_column' => null,
        'initial_status_column' => null,
        'task_column' => null,
        'task_values' => ['1', 'ya', 'true'],
        'task_conditions' => [],
        'required_columns' => [],
        'display_columns' => [],
        'statuses' => [],
    ];

    /** @var array<string, mixed> */
    private array $settings;

    private ?StatusSet $statusSet = null;

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(?array $settings)
    {
        $this->settings = array_merge(self::DEFAULTS, array_filter($settings ?? [], fn ($value) => $value !== null));
    }

    public function unitLabel(): string
    {
        return trim((string) $this->settings['unit_label']) ?: 'Unit';
    }

    public function unitSource(): string
    {
        return $this->settings['unit_source'] === self::UNIT_FILE ? self::UNIT_FILE : self::UNIT_KECAMATAN;
    }

    public function keyMode(): string
    {
        return $this->settings['key_mode'] === self::KEY_ROW ? self::KEY_ROW : self::KEY_COLUMN;
    }

    public function keyColumn(): ?string
    {
        return self::blankToNull($this->settings['key_column']);
    }

    /**
     * Kolom pembeda bila satu kunci berisi beberapa baris (mis. satu assignment dengan beberapa usaha):
     * kunci baris = kolom kunci + kolom ini. Hanya untuk pencocokan lewat kolom kunci.
     */
    public function keyDetailColumn(): ?string
    {
        return $this->keyMode() === self::KEY_COLUMN ? self::blankToNull($this->settings['key_detail_column'] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    public function reportKeyFields(): array
    {
        return self::toList($this->settings['report_key_fields']);
    }

    public function recapColumn(): ?string
    {
        return self::blankToNull($this->settings['recap_column']);
    }

    public function initialStatusColumn(): ?string
    {
        return self::blankToNull($this->settings['initial_status_column']);
    }

    /**
     * Kolom penanda baris yang perlu dikerjakan (mis. "Edit KBLI (1=Ya)"): bila diisi, hanya baris
     * yang nilainya ada di taskValues() yang jadi target & dihitung progress-nya.
     */
    public function taskColumn(): ?string
    {
        return self::blankToNull($this->settings['task_column'] ?? null);
    }

    /**
     * @return list<string> nilai (huruf kecil) yang berarti "dikerjakan"
     */
    public function taskValues(): array
    {
        return array_map('strtolower', self::toList($this->settings['task_values'] ?? self::DEFAULTS['task_values']));
    }

    /** Pembanding yang didukung saringan tambahan (yang dua karakter dicek dulu). */
    public const CONDITION_OPERATORS = ['>=', '<=', '!=', '=', '>', '<'];

    /**
     * Saringan tambahan baris target, mis. "skor_kecocokan >= 80": baris yang tidak memenuhi semua syarat
     * tetap tampil, tapi tidak dihitung.
     *
     * @return list<array{column: string, operator: string, value: string}>
     */
    public function taskConditions(): array
    {
        return array_values(array_filter(array_map(
            self::parseCondition(...),
            self::toConditionList($this->settings['task_conditions'] ?? []),
        )));
    }

    /**
     * Satu syarat "kolom operator nilai"; null bila tidak bisa dibaca.
     *
     * @return array{column: string, operator: string, value: string}|null
     */
    public static function parseCondition(string $condition): ?array
    {
        $pattern = '/^(.+?)\s*('.implode('|', array_map(fn (string $operator) => preg_quote($operator, '/'), self::CONDITION_OPERATORS)).')\s*(.*)$/u';

        if (! preg_match($pattern, trim($condition), $match) || trim($match[1]) === '' || trim($match[3]) === '') {
            return null;
        }

        return ['column' => trim($match[1]), 'operator' => $match[2], 'value' => trim($match[3])];
    }

    /**
     * Apakah isi sel memenuhi syarat. Angka dibandingkan sebagai angka, selain itu sebagai teks
     * (huruf besar/kecil tidak dibedakan).
     *
     * @param  array{column: string, operator: string, value: string}  $condition
     */
    public static function meetsCondition(mixed $cell, array $condition): bool
    {
        $cell = trim(is_bool($cell) ? ($cell ? 'true' : 'false') : (string) $cell);
        $value = $condition['value'];

        if (is_numeric($cell) && is_numeric($value)) {
            $cell = (float) $cell;
            $value = (float) $value;
        } else {
            $cell = mb_strtolower($cell);
            $value = mb_strtolower($value);
        }

        return match ($condition['operator']) {
            '>=' => $cell >= $value,
            '<=' => $cell <= $value,
            '>' => $cell > $value,
            '<' => $cell < $value,
            '!=' => $cell != $value,
            default => $cell == $value,
        };
    }

    /**
     * Syarat dipisah titik koma atau baris baru (koma bisa jadi bagian nilai).
     *
     * @return list<string>
     */
    public static function toConditionList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[;\r\n]+/', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== ''));
    }

    /**
     * Kolom yang harus terisi supaya baris bisa dikerjakan (mis. "KBLI Baru"); kosong = "Belum siap".
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return self::toList($this->settings['required_columns'] ?? []);
    }

    /**
     * Apakah baris dengan isi kolom penanda ini termasuk yang dikerjakan.
     */
    public function isTaskValue(mixed $value): bool
    {
        $value = strtolower(trim(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));

        return in_array($value, $this->taskValues(), true);
    }

    /**
     * @return list<string>
     */
    public function displayColumns(): array
    {
        return self::toList($this->settings['display_columns']);
    }

    public function statuses(): StatusSet
    {
        return $this->statusSet ??= new StatusSet($this->settings['statuses']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->settings, 'statuses' => $this->statuses()->toArray()];
    }

    /**
     * @return list<string>
     */
    public static function toList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== ''));
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
