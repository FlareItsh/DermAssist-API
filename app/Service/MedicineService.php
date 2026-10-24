<?php

namespace App\Service;

use App\Models\Medicine;
use App\Models\MedicineVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MedicineService
{
    /**
     * List all medicines added by this doctor.
     */
    public function listMedicines(User $user): Collection
    {
        $doctor = $this->resolveDoctor($user);

        return Medicine::query()
            ->where('doctor_id', $doctor->id)
            ->with(['variants' => function ($query) {
                $query->orderBy('id', 'asc');
            }])
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Get a specific medicine owned by the doctor.
     */
    public function getMedicine(User $user, string $uuid): Medicine
    {
        $doctor = $this->resolveDoctor($user);

        $medicine = Medicine::with(['variants' => function ($query) {
            $query->orderBy('id', 'asc');
        }])
            ->where('uuid', $uuid)
            ->where('doctor_id', $doctor->id)
            ->first();

        if (! $medicine) {
            abort(404, 'Medicine not found.');
        }

        return $medicine;
    }

    /**
     * Create a new doctor-specific medicine with dosage variants.
     */
    public function createMedicine(User $user, array $data): Medicine
    {
        $doctor = $this->resolveDoctor($user);

        return DB::transaction(function () use ($doctor, $data) {
            $medicine = Medicine::create([
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $doctor->id,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
            ]);

            foreach ($data['variants'] as $v) {
                MedicineVariant::create([
                    'uuid' => (string) Str::uuid(),
                    'medicine_id' => $medicine->id,
                    'dosage' => trim($v['dosage']),
                    'unit' => trim($v['unit']),
                    'default_frequency' => trim($v['default_frequency']),
                ]);
            }

            return $medicine->load('variants');
        });
    }

    /**
     * Update an existing doctor-owned medicine and sync variants.
     */
    public function updateMedicine(User $user, string $uuid, array $data): Medicine
    {
        $doctor = $this->resolveDoctor($user);

        $medicine = Medicine::where('uuid', $uuid)->first();
        if (! $medicine) {
            abort(404, 'Medicine not found.');
        }

        if ($medicine->doctor_id !== $doctor->id) {
            abort(403, 'Unauthorized to modify system or other doctor medicines.');
        }

        return DB::transaction(function () use ($medicine, $data) {
            $medicine->update([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
            ]);

            $retainedVariantIds = [];

            foreach ($data['variants'] as $v) {
                $variantUuid = $v['uuid'] ?? null;
                $existingVariant = null;

                if ($variantUuid) {
                    $existingVariant = MedicineVariant::where('uuid', $variantUuid)
                        ->where('medicine_id', $medicine->id)
                        ->first();
                }

                if ($existingVariant) {
                    $existingVariant->update([
                        'dosage' => trim($v['dosage']),
                        'unit' => trim($v['unit']),
                        'default_frequency' => trim($v['default_frequency']),
                    ]);
                    $retainedVariantIds[] = $existingVariant->id;
                } else {
                    $newVariant = MedicineVariant::create([
                        'uuid' => (string) Str::uuid(),
                        'medicine_id' => $medicine->id,
                        'dosage' => trim($v['dosage']),
                        'unit' => trim($v['unit']),
                        'default_frequency' => trim($v['default_frequency']),
                    ]);
                    $retainedVariantIds[] = $newVariant->id;
                }
            }

            // Remove any variants that were not in the updated payload
            MedicineVariant::where('medicine_id', $medicine->id)
                ->whereNotIn('id', $retainedVariantIds)
                ->delete();

            return $medicine->fresh(['variants']);
        });
    }

    /**
     * Delete a doctor-owned medicine.
     */
    public function deleteMedicine(User $user, string $uuid): bool
    {
        $doctor = $this->resolveDoctor($user);

        $medicine = Medicine::where('uuid', $uuid)->first();
        if (! $medicine) {
            abort(404, 'Medicine not found.');
        }

        if ($medicine->doctor_id !== $doctor->id) {
            abort(403, 'Unauthorized to delete system or other doctor medicines.');
        }

        return (bool) $medicine->delete();
    }

    /**
     * Resolve supervising doctor for user (doctor or doctor's secretary).
     */
    private function resolveDoctor(User $user): User
    {
        if ($user->role?->slug === 'doctor') {
            return $user;
        }

        if ($user->role?->slug === 'secretary' && $user->doctor_id) {
            $doctor = User::find($user->doctor_id);
            if ($doctor) {
                return $doctor;
            }
        }

        abort(403, 'Only doctors and clinic staff can access the medicine library.');
    }
}
