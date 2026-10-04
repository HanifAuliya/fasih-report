<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['project_id', 'kecamatan_id', 'category', 'original_name', 'path', 'extension', 'size', 'notes', 'summary', 'is_active', 'uploaded_by'])]
class ReportFile extends Model
{
    public const CATEGORIES = [
        'report' => 'Report',
        'target' => 'Target',
        'bagian' => 'Pembagian',
        'lainnya' => 'Lainnya',
    ];

    public const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'json', 'txt', 'js', 'pdf', 'zip'];

    protected static function booted(): void
    {
        static::deleted(fn (ReportFile $file) => Storage::disk('local')->delete($file->path));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isStatusReport(): bool
    {
        return $this->category === 'report' && in_array($this->extension, ['json', 'csv'], true);
    }

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i ? 1 : 0).' '.$units[$i];
    }

    public function isJson(): bool
    {
        return $this->extension === 'json';
    }

    public function isPreviewable(): bool
    {
        return in_array($this->extension, ['json', 'txt', 'js', 'csv']);
    }
}
