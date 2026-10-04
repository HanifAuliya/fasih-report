<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'kode', 'nama', 'target', 'realisasi', 'status', 'catatan'])]
class Kecamatan extends Model
{
    public const STATUSES = [
        'belum' => 'Belum',
        'proses' => 'Proses',
        'selesai' => 'Selesai',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'realisasi' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }

    /**
     * Riwayat laporan JSON/CSV yang diupload untuk unit ini (terbaru dulu).
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ReportFile::class)
            ->where('category', 'report')
            ->whereIn('extension', ['json', 'csv'])
            ->latest('id');
    }

    public function targetSheets(): HasMany
    {
        return $this->hasMany(TargetSheet::class)->orderBy('position');
    }

    public function targetRows(): HasMany
    {
        return $this->hasMany(TargetRow::class);
    }

    /**
     * Hitung target & realisasi dari baris Excel target (jika sudah diimpor).
     */
    public function syncProgressFromTargets(): void
    {
        $total = $this->targetRows()->tracked()->count();

        if ($total === 0) {
            return;
        }

        $this->target = $total;
        $this->realisasi = $this->targetRows()->tracked()->done($this->project->config()->statuses()->doneCodes())->count();
        $this->syncStatus();
        $this->save();
    }

    public function percent(): int
    {
        if ($this->target > 0) {
            return (int) min(100, round($this->realisasi / $this->target * 100));
        }

        return match ($this->status) {
            'selesai' => 100,
            'proses' => 50,
            default => 0,
        };
    }

    /**
     * Tentukan status otomatis dari angka target & realisasi.
     */
    public function syncStatus(): void
    {
        if ($this->target <= 0) {
            return;
        }

        $this->status = match (true) {
            $this->realisasi >= $this->target => 'selesai',
            $this->realisasi > 0 => 'proses',
            default => 'belum',
        };
    }
}
