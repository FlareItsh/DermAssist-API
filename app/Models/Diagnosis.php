<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['uuid', 'user_uuid', 'patient_uuid', 'doctor_uuid', 'image_path', 'label', 'confidence', 'probabilities', 'status', 'patient_consented_dataset', 'contributed_to_dataset', 'contributed_at', 'out_of_scope_category', 'is_out_of_scope', 'out_of_scope_contributed', 'out_of_scope_contributed_at'])]
class Diagnosis extends Model
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
            'probabilities' => 'array',
            'confidence' => 'float',
            'patient_consented_dataset' => 'boolean',
            'contributed_to_dataset' => 'boolean',
            'contributed_at' => 'datetime',
            'is_out_of_scope' => 'boolean',
            'out_of_scope_contributed' => 'boolean',
            'out_of_scope_contributed_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Specify the UUID column name.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_uuid', 'uuid');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_uuid', 'uuid');
    }

    public function clinicalNote()
    {
        return $this->hasOne(ClinicalNote::class);
    }
}
