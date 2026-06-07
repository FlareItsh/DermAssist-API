<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClinicalNote extends Model
{
    use HasFactory, \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'uuid',
        'appointment_id',
        'doctor_id',
        'patient_id',
        'diagnosis_id',
        'history_of_present_illness',
        'systemic_symptoms',
        'physical_exam',
        'differential_diagnosis',
        'final_diagnosis',
        'prescription',
        'patient_education',
        'follow_up_date',
        'follow_up_instructions',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
    ];

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
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
        return $this->belongsTo(Diagnosis::class);
    }
}
