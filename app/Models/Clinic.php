<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'uuid',
    'owner_doctor_id',
    'name',
    'address',
    'phone',
    'email',
    'geo_latitude',
    'geo_longitude',
    'is_active',
])]
class Clinic extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'geo_latitude' => 'decimal:8',
            'geo_longitude' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_doctor_id');
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clinic_doctors', 'clinic_id', 'doctor_user_id')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }
}
