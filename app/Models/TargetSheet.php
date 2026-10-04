<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kecamatan_id', 'report_file_id', 'name', 'position', 'headers', 'tracked', 'row_count'])]
class TargetSheet extends Model
{
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'tracked' => 'boolean',
        ];
    }

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ReportFile::class, 'report_file_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(TargetRow::class)->orderBy('row_number');
    }

    /**
     * Sheet yang barisnya diproses script dan dihitung progress-nya.
     */
    public function isTracked(): bool
    {
        return $this->tracked;
    }

    /**
     * Index kolom berdasarkan nama header (tanpa beda huruf besar/kecil & titik dua di akhir).
     */
    public function columnIndex(string $name): ?int
    {
        foreach ($this->headers as $index => $header) {
            if (self::normalizeHeader($header) === self::normalizeHeader($name)) {
                return $index;
            }
        }

        return null;
    }

    public static function normalizeHeader(string $header): string
    {
        return strtolower(rtrim(trim($header), ': '));
    }
}
