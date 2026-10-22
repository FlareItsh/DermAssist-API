<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'diagnosis_id', 'parent_appeal_id', 'resubmission_count', 'diagnosis_label', 'suggested_label', 'description', 'status', 'reviewer_id', 'resolved_at', 'resolution_notes'])]
class Appeal extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'resubmission_count' => 'integer',
        ];
    }

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

    /**
     * Get the reviewer who resolved or rejected the appeal.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Get the diagnosis associated with this appeal.
     */
    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }

    /**
     * Get the preceding parent appeal in the resubmission chain.
     */
    public function parentAppeal(): BelongsTo
    {
        return $this->belongsTo(Appeal::class, 'parent_appeal_id');
    }

    /**
     * Get any downstream resubmissions linked to this appeal.
     */
    public function childAppeals(): HasMany
    {
        return $this->hasMany(Appeal::class, 'parent_appeal_id');
    }
}
