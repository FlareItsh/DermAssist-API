<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AppointmentRescheduleProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'appointment_id',
        'proposer_id',
        'proposer_type',
        'proposed_start_at',
        'proposed_end_at',
        'reason',
        'status',
        'responder_id',
        'responded_at',
        'expires_at',
    ];

    protected $casts = [
        'proposed_start_at' => 'datetime',
        'proposed_end_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $appends = [
        'proposer_party',
    ];

    public function getProposerPartyAttribute(): string
    {
        return in_array($this->proposer_type, ['doctor', 'secretary']) ? 'practice' : 'patient';
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
