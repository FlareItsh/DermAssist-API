<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'user_uuid',
    'patient_uuid',
    'doctor_uuid',
    'image_path',
    'label',
    'original_ai_label',
    'scan_type',
    'is_technical_failure',
    'technical_failure_reason',
    'confidence',
    'probabilities',
    'status',
    'patient_consented_dataset',
    'doctor_authorized_dataset',
    'doctor_authorized_at',
    'admin_approved_dataset',
    'admin_approved_at',
    'admin_approved_by',
    'contributed_to_dataset',
    'contributed_at',
    'out_of_scope_category',
    'is_out_of_scope',
    'out_of_scope_contributed',
    'out_of_scope_contributed_at',
])]
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
            'is_technical_failure' => 'boolean',
            'patient_consented_dataset' => 'boolean',
            'doctor_authorized_dataset' => 'boolean',
            'doctor_authorized_at' => 'datetime',
            'admin_approved_dataset' => 'boolean',
            'admin_approved_at' => 'datetime',
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

    public function adminApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_approved_by');
    }

    public function clinicalNote()
    {
        return $this->hasOne(ClinicalNote::class);
    }

    public function appeals()
    {
        return $this->hasMany(Appeal::class);
    }

    public function appeal()
    {
        return $this->hasOne(Appeal::class)->latestOfMany();
    }

    /**
     * Verify if the scan satisfies the Complete Three-Gate Dataset Eligibility Rule.
     */
    public function isEligibleForDataset(): bool
    {
        if ($this->is_technical_failure || empty($this->label)) {
            return false;
        }

        // Gate 1: Patient Consent
        $patient = $this->patient;
        if (! empty($this->patient_uuid)) {
            $gate1PatientConsent = (bool) $this->patient_consented_dataset && ($patient ? (bool) $patient->consent_dataset : false);
        } else {
            $gate1PatientConsent = (bool) $this->patient_consented_dataset;
        }

        // Gate 2: Doctor Authorization
        $gate2DoctorAuth = (bool) $this->doctor_authorized_dataset
            || (! empty($this->doctor_uuid) && $this->scan_type === 'in_clinic')
            || ($this->appeal && $this->appeal->user?->role?->slug === 'doctor');

        // Gate 3: Admin Approval (via admin approval flag or resolved appeal)
        $gate3AdminApproval = (bool) $this->admin_approved_dataset
            || ($this->appeal && $this->appeal->status === 'resolved');

        return $gate1PatientConsent && $gate2DoctorAuth && $gate3AdminApproval;
    }
}
