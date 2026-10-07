<?php

namespace App\Models;

use App\Enums\ProjectType;
use App\Support\ProjectSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'type', 'settings', 'description', 'color'])]
class Project extends Model
{
    public const COLORS = ['indigo', 'emerald', 'sky', 'amber', 'rose', 'violet', 'teal', 'slate'];

    protected $attributes = [
        'type' => 'oss',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'settings' => 'array',
        ];
    }

    /**
     * Kelas warna aksen data (garis atas kartu, latar lembut, ikon).
     *
     * @return array{bar: string, soft: string, text: string, ring: string}
     */
    public function accent(): array
    {
        return match ($this->color) {
            'emerald' => ['bar' => 'bg-emerald-500', 'soft' => 'from-emerald-100/80', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-500/20'],
            'sky' => ['bar' => 'bg-sky-500', 'soft' => 'from-sky-100/80', 'text' => 'text-sky-700', 'ring' => 'ring-sky-500/20'],
            'amber' => ['bar' => 'bg-amber-500', 'soft' => 'from-amber-100/80', 'text' => 'text-amber-700', 'ring' => 'ring-amber-500/20'],
            'rose' => ['bar' => 'bg-rose-500', 'soft' => 'from-rose-100/80', 'text' => 'text-rose-700', 'ring' => 'ring-rose-500/20'],
            'violet' => ['bar' => 'bg-violet-500', 'soft' => 'from-violet-100/80', 'text' => 'text-violet-700', 'ring' => 'ring-violet-500/20'],
            'teal' => ['bar' => 'bg-teal-500', 'soft' => 'from-teal-100/80', 'text' => 'text-teal-700', 'ring' => 'ring-teal-500/20'],
            'slate' => ['bar' => 'bg-slate-500', 'soft' => 'from-slate-200/80', 'text' => 'text-slate-700', 'ring' => 'ring-slate-500/20'],
            default => ['bar' => 'bg-brand-500', 'soft' => 'from-brand-100/80', 'text' => 'text-brand-700', 'ring' => 'ring-brand-500/20'],
        };
    }

    /**
     * Pengaturan cara membaca Excel & laporan (kolom kunci, unit, status, dll).
     */
    public function config(): ProjectSettings
    {
        return new ProjectSettings($this->settings ?? $this->type->settings());
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->settings ??= $project->type->settings();
        });

        static::saving(function (Project $project) {
            if (blank($project->slug)) {
                $project->slug = static::uniqueSlug($project->name, $project->id);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'data';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function kecamatans(): HasMany
    {
        return $this->hasMany(Kecamatan::class)->orderBy('kode');
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class)->orderBy('name');
    }

    /**
     * Baris file induk (Excel utuh); status diambil dari baris unit yang kuncinya sama.
     */
    public function masterRows(): HasMany
    {
        return $this->hasMany(MasterRow::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class)->latest();
    }

    /**
     * Progress keseluruhan (0-100) berdasarkan rata-rata progress tiap kecamatan.
     */
    public function progress(): int
    {
        $kecamatans = $this->relationLoaded('kecamatans') ? $this->kecamatans : $this->kecamatans()->get();

        if ($kecamatans->isEmpty()) {
            return 0;
        }

        // Ada data baris: progress = baris selesai / semua baris (unit tanpa data tidak menurunkan angka).
        // Dibulatkan ke bawah supaya 100% hanya muncul bila benar-benar selesai.
        $target = $kecamatans->sum('target');

        if ($target > 0) {
            return (int) min(100, floor($kecamatans->sum('realisasi') / $target * 100));
        }

        return (int) round($kecamatans->avg(fn (Kecamatan $k) => $k->percent()));
    }
}
