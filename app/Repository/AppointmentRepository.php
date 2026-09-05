<?php

namespace App\Repository;

use App\Models\Appointment;

class AppointmentRepository
{
    public function getAppointmentsForUser($user, $doctorId = null, $doctorUuid = null)
    {
        $query = Appointment::with(['doctor', 'patient', 'diagnosis', 'clinicalNote.diagnosis']);

        if ($doctorId || $doctorUuid) {
            $query->whereHas('doctor', function ($q) use ($doctorId, $doctorUuid) {
                if ($doctorId) {
                    $q->where('id', $doctorId);
                }
                if ($doctorUuid) {
                    $q->where('uuid', $doctorUuid);
                }
            });
            $query->whereIn('status', ['scheduled', 'reschedule_proposed', 'reschedule_requested']);
        } elseif ($user->role->slug === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->role->slug === 'secretary') {
            $query->where('doctor_id', $user->doctor_id);
        } elseif ($user->role->slug === 'patient') {
            $query->where('patient_id', $user->id);
        }

        return $query->latest()->get();
    }

    public function createAppointment(array $data)
    {
        return Appointment::create($data);
    }

    public function updateAppointment(Appointment $appointment, array $data)
    {
        $appointment->update($data);

        return $appointment;
    }

    public function deleteAppointment(Appointment $appointment)
    {
        return $appointment->delete();
    }
}
