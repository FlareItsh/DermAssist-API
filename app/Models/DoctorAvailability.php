<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['uuid', 'doctor_id', 'available_date', 'start_time', 'end_time', 'is_available'])]
class DoctorAvailability extends Model
{
    use HasUuids;

    protected $keyType = 'int';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'available_date' => 'date',
            'is_available' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
