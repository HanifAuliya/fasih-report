<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'kecamatan_id', 'target_sheet_id', 'row_number', 'row_key', 'cells', 'status', 'reason', 'result', 'status_at', 'status_file_id'])]
class TargetRow extends Model
{
    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'result' => 'array',
            'status_at' => 'datetime',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(TargetSheet::class, 'target_sheet_id');
    }

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function statusFile(): BelongsTo
    {
        return $this->belongsTo(ReportFile::class, 'status_file_id');
    }

    /**
     * Baris yang dihitung progress-nya (punya kunci pencocokan: assignment_id atau sheet!baris).
     *
     * @param  Builder<TargetRow>  $query
     */
    public function scopeTracked(Builder $query): void
    {
        $query->whereNotNull('row_key');
    }

    /**
     * @param  Builder<TargetRow>  $query
     * @param  list<string>  $doneCodes
     */
    public function scopeDone(Builder $query, array $doneCodes): void
    {
        $query->whereIn('status', $doneCodes);
    }
}
