<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris file induk pekerjaan (lihat MasterWorkbook).
 */
#[Fillable(['project_id', 'report_file_id', 'row_number', 'row_key', 'is_task'])]
class MasterRow extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_task' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
