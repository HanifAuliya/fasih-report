<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Daftar status baris yang bisa diatur per manajemen data.
 *
 * Tiap status: kode (disimpan di database), label, warna, apakah dihitung selesai,
 * dan alias (teks status dari laporan/Excel yang dianggap sama dengan status ini).
 */
class StatusSet
{
    public const COLORS = [
        'slate' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-500/20', 'row' => '', 'dot' => 'bg-slate-400'],
        'blue' => ['badge' => 'bg-blue-50 text-blue-700 ring-blue-600/20', 'row' => '', 'dot' => 'bg-blue-500'],
        'violet' => ['badge' => 'bg-violet-50 text-violet-700 ring-violet-600/20', 'row' => 'bg-violet-50/40', 'dot' => 'bg-violet-500'],
        'emerald' => ['badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'row' => 'bg-emerald-50/50', 'dot' => 'bg-emerald-500'],
        'teal' => ['badge' => 'bg-teal-50 text-teal-700 ring-teal-600/20', 'row' => 'bg-teal-50/50', 'dot' => 'bg-teal-500'],
        'cyan' => ['badge' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20', 'row' => 'bg-cyan-50/40', 'dot' => 'bg-cyan-500'],
        'amber' => ['badge' => 'bg-amber-50 text-amber-700 ring-amber-600/20', 'row' => 'bg-amber-50/50', 'dot' => 'bg-amber-500'],
        'rose' => ['badge' => 'bg-rose-50 text-rose-700 ring-rose-600/20', 'row' => 'bg-rose-50/50', 'dot' => 'bg-rose-500'],
    ];

    /** @var Collection<string, array{code: string, label: string, color: string, done: bool, aliases: list<string>}> */
    private Collection $statuses;

    /**
     * @param  list<array{code: string, label: string, color?: string, done?: bool, aliases?: list<string>|string}>  $statuses
     */
    public function __construct(array $statuses)
    {
        $this->statuses = collect($statuses)
            ->filter(fn (array $status) => trim((string) ($status['code'] ?? '')) !== '')
            ->map(fn (array $status) => [
                'code' => strtolower(trim($status['code'])),
                'label' => trim((string) ($status['label'] ?? '')) ?: $status['code'],
                'color' => array_key_exists($status['color'] ?? '', self::COLORS) ? $status['color'] : 'slate',
                'done' => (bool) ($status['done'] ?? false),
                'aliases' => self::parseAliases($status['aliases'] ?? []),
            ])
            ->keyBy('code');
    }

    /**
     * @param  list<string>|string  $aliases
     * @return list<string>
     */
    public static function parseAliases(array|string $aliases): array
    {
        $aliases = is_string($aliases) ? explode(',', $aliases) : $aliases;

        return array_values(array_unique(array_filter(array_map(fn ($alias) => strtolower(trim((string) $alias)), $aliases))));
    }

    /**
     * @return Collection<string, array{code: string, label: string, color: string, done: bool, aliases: list<string>}>
     */
    public function all(): Collection
    {
        return $this->statuses;
    }

    public function has(?string $code): bool
    {
        return $code !== null && $this->statuses->has($code);
    }

    /**
     * Status awal untuk baris baru: status pertama dalam daftar.
     */
    public function defaultCode(): ?string
    {
        return $this->statuses->keys()->first();
    }

    /**
     * @return list<string>
     */
    public function doneCodes(): array
    {
        return $this->statuses->where('done', true)->keys()->values()->all();
    }

    public function isDone(?string $code): bool
    {
        return (bool) ($this->statuses[$code]['done'] ?? false);
    }

    public function label(?string $code): string
    {
        return $this->statuses[$code]['label'] ?? (string) $code;
    }

    public function badgeClasses(?string $code): string
    {
        return self::COLORS[$this->statuses[$code]['color'] ?? 'slate']['badge'];
    }

    public function rowClasses(?string $code): string
    {
        return self::COLORS[$this->statuses[$code]['color'] ?? 'slate']['row'];
    }

    public function hasAlias(?string $code, string $alias): bool
    {
        return in_array(strtolower($alias), $this->statuses[$code]['aliases'] ?? [], true);
    }

    /**
     * Cocokkan teks status dari laporan/Excel ke kode status.
     * Urutan: kode, label, alias persis; lalu bagian sebelum ":" (mis. "KUNING: desa tidak terdeteksi").
     */
    public function resolve(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        foreach ([$value, trim(explode(':', $value, 2)[0])] as $candidate) {
            foreach ($this->statuses as $code => $status) {
                if ($candidate === $code || $candidate === strtolower($status['label']) || in_array($candidate, $status['aliases'], true)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, label: string, color: string, done: bool, aliases: list<string>}>
     */
    public function toArray(): array
    {
        return $this->statuses->values()->all();
    }
}
