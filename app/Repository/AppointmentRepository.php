<?php

namespace App\Repository;

use App\Models\Appointment;

class AppointmentRepository
{
    public function getAppointmentsForUser($user)
    {
        $query = Appointment::with(['doctor', 'patient', 'diagnosis', 'clinicalNote.diagnosis']);

        if ($user->role->slug === 'doctor') {
            $query->where('doctor_id', $user->id);
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
