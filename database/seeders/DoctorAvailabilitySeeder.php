<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\DoctorAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DoctorAvailabilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doctors = User::whereHas('role', fn ($q) => $q->where('slug', 'doctor'))->get();

        if ($doctors->isEmpty()) {
            $this->command->info('No doctor accounts found to seed availabilities.');

            return;
        }

        $now = Carbon::today();

        foreach ($doctors as $doctor) {
            // 1. Seed or retrieve clinics for this doctor
            $primaryClinic = Clinic::firstOrCreate(
                [
                    'owner_doctor_id' => $doctor->id,
                    'name' => "{$doctor->last_name} Dermatology & Skin Clinic",
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'address' => 'Suite 405, Makati Medical Plaza, Amorsolo St, Makati City',
                    'phone' => '+63 (02) 8888-2300',
                    'email' => strtolower($doctor->first_name).'.clinic@dermassist.ph',
                    'geo_latitude' => 14.5547,
                    'geo_longitude' => 121.0160,
                    'is_active' => true,
                ]
            );

            $secondaryClinic = Clinic::firstOrCreate(
                [
                    'owner_doctor_id' => $doctor->id,
                    'name' => "St. Luke's Medical Center - Rm 512",
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'address' => '32nd Street, Bonifacio Global City, Taguig, Metro Manila',
                    'phone' => '+63 (02) 8789-7700',
                    'email' => 'bgc.derma@stlukes.com.ph',
                    'geo_latitude' => 14.5492,
                    'geo_longitude' => 121.0475,
                    'is_active' => true,
                ]
            );

            // 2. Clear old test availabilities for this doctor to keep it fresh & deterministic
            DoctorAvailability::where('doctor_id', $doctor->id)->delete();

            // 3. Seed schedules for the next 14 days
            for ($dayOffset = 0; $dayOffset <= 14; $dayOffset++) {
                $targetDate = $now->copy()->addDays($dayOffset);
                $dayOfWeek = $targetDate->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday

                // Skip Sundays
                if ($dayOfWeek === Carbon::SUNDAY) {
                    // Block entire day on Sunday
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => null,
                        'location_name' => 'Rest Day / Off-Duty',
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '00:00',
                        'end_time' => '23:59',
                        'is_available' => false,
                    ]);

                    continue;
                }

                // Mon / Wed / Fri: Split between Primary Clinic and Secondary Clinic
                if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                    // Morning shift at Primary Clinic
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => $primaryClinic->id,
                        'location_name' => $primaryClinic->name,
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '09:00',
                        'end_time' => '12:00',
                        'is_available' => true,
                    ]);

                    // Lunch away break
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => null,
                        'location_name' => 'Lunch & Transit Break',
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '12:00',
                        'end_time' => '13:30',
                        'is_available' => false,
                    ]);

                    // Afternoon shift at St. Luke's BGC
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => $secondaryClinic->id,
                        'location_name' => $secondaryClinic->name,
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '13:30',
                        'end_time' => '17:00',
                        'is_available' => true,
                    ]);
                } else {
                    // Tue / Thu / Sat: Full day at Primary Clinic with lunch break
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => $primaryClinic->id,
                        'location_name' => $primaryClinic->name,
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '09:00',
                        'end_time' => '17:00',
                        'is_available' => true,
                    ]);

                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doctor->id,
                        'clinic_id' => null,
                        'location_name' => 'Lunch Break',
                        'available_date' => $targetDate->toDateString(),
                        'start_time' => '12:00',
                        'end_time' => '13:00',
                        'is_available' => false,
                    ]);
                }
            }
        }

        $this->command->info('Successfully seeded doctor clinics and 14-day duty & away availability blocks.');
    }
}
