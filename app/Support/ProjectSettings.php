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

    public const DEFAULTS = [
        'unit_label' => 'Kecamatan',
        'unit_source' => self::UNIT_KECAMATAN,
        'key_mode' => self::KEY_COLUMN,
        'key_column' => 'assignment_id',
        'report_key_fields' => ['assignment_id', 'id'],
        'recap_column' => null,
        'initial_status_column' => null,
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
