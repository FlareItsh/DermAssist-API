<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['uuid', 'user_uuid', 'patient_uuid', 'doctor_uuid', 'image_path', 'label', 'confidence', 'probabilities', 'status'])]
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
}
