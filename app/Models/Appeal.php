<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'diagnosis_label', 'suggested_label', 'description', 'status'])]
class Appeal extends Model
{
    use HasUuids;

    /**
     * The unique ID columns.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the user who submitted the appeal.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
