<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['script_id', 'version', 'code', 'notes'])]
class ScriptVersion extends Model
{
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }
}
