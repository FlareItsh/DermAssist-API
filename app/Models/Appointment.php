<?php

namespace App\Models;

use App\Service\ScanQuotaService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'doctor_id',
        'patient_id',
        'diagnosis_id',
        'in_clinic_diagnosis_id',
        'scan_exception_reason',
        'scan_exception_category',
        'scan_exception_granted_at',
        'scan_exception_doctor_id',
        'scheduled_at',
        'scheduled_end_at',
        'previous_scheduled_at',
        'previous_scheduled_end_at',
        'requested_reschedule_date',
        'requested_reschedule_time',
        'location',
        'purpose',
        'status',
        'accepted_by_id',
        'confirmed_at',
        'cancelled_by_id',
        'cancelled_at',
        'cancellation_reason',
        'no_show_by_id',
        'no_show_at',
        'no_show_reason',
        'checked_in_at',
        'consultation_started_at',
        'clinic_id',
        'appointment_source',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'previous_scheduled_at' => 'datetime',
        'previous_scheduled_end_at' => 'datetime',
        'requested_reschedule_date' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'no_show_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'consultation_started_at' => 'datetime',
        'scan_exception_granted_at' => 'datetime',
    ];

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

        static::saved(function ($model) {
            if ($model->status === 'completed' && ($model->wasRecentlyCreated || $model->wasChanged('status'))) {
                $days = app(ScanQuotaService::class)->getAppointmentUnlimitedDays();
                $model->patient?->grantUnlimitedScans($days);
            }
        });
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function diagnosis()
    {
        return $this->belongsTo(Diagnosis::class, 'diagnosis_id');
    }

    public function inClinicDiagnosis()
    {
        return $this->belongsTo(Diagnosis::class, 'in_clinic_diagnosis_id');
    }

    public function scanExceptionDoctor()
    {
        return $this->belongsTo(User::class, 'scan_exception_doctor_id');
    }

    /**
     * Checks if the consultation has satisfied the mandatory in-clinic scan gate
     * via either a successful in-clinic scan or an authorized documented exception.
     */
    public function hasSatisfiedConsultationScan(): bool
    {
        if ($this->in_clinic_diagnosis_id && $this->inClinicDiagnosis && ! $this->inClinicDiagnosis->is_technical_failure && $this->inClinicDiagnosis->status === 'completed') {
            return true;
        }

        if ($this->clinicalNote && $this->clinicalNote->diagnosis && ! $this->clinicalNote->diagnosis->is_technical_failure && $this->clinicalNote->diagnosis->status === 'completed') {
            if ($this->clinicalNote->diagnosis->scan_type !== 'home') {
                return true;
            }
        }

        if (! empty($this->scan_exception_reason) && $this->scan_exception_granted_at) {
            return true;
        }

        return false;
    }

    public function clinicalNote()
    {
        return $this->hasOne(ClinicalNote::class);
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function noShowBy()
    {
        return $this->belongsTo(User::class, 'no_show_by_id');
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function rescheduleProposals()
    {
        return $this->hasMany(AppointmentRescheduleProposal::class, 'appointment_id');
    }

    public function activeRescheduleProposal()
    {
        return $this->hasOne(AppointmentRescheduleProposal::class, 'appointment_id')->where('status', 'pending');
    }

    public function events()
    {
        return $this->hasMany(AppointmentEvent::class, 'appointment_id')->latest();
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, ['confirmed', 'scheduled', 'checked_in', 'in_consultation']);
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }
}
