<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\DoctorAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DoctorAvailabilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch Doctor
        $doctor = User::where('email', 'ben@derm.com')->first();
        if (! $doctor) {
            return;
        }

        // 2. Safeguard foreign keys before replacing clinics and availabilities
        DB::table('appointments')->whereNotNull('clinic_id')->update(['clinic_id' => null]);
        DoctorAvailability::where('doctor_id', $doctor->id)->delete();
        DB::table('clinic_doctors')->where('doctor_user_id', $doctor->id)->delete();
        Clinic::where('owner_doctor_id', $doctor->id)->delete();

        // 3. Create Doctor's Clinics in Davao City (Multi-Clinic Doctor Plan supports multiple clinics)
        $clinicSpecialists = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $doctor->id,
            'name' => 'Davao Skin Specialists Clinic',
            'address' => 'Room 302, Medical Arts Building, Davao Doctors Hospital, E. Quirino Ave, Davao City',
            'phone' => '+63 (82) 222-8000',
            'email' => 'davao.specialists@derm.com',
            'geo_latitude' => 7.0682,
            'geo_longitude' => 125.6075,
            'is_active' => true,
        ]);

        $clinicMatina = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $doctor->id,
            'name' => 'DermCare Center - Matina',
            'address' => '2nd Floor, Matina Town Square, McArthur Highway, Matina, Davao City',
            'phone' => '+63 (82) 297-5000',
            'email' => 'matina.care@derm.com',
            'geo_latitude' => 7.0585,
            'geo_longitude' => 125.5905,
            'is_active' => true,
        ]);

        // 4. Seed Schedule from September 1, 2026 until November 20, 2026
        $startDate = Carbon::parse('2026-09-01');
        $endDate = Carbon::parse('2026-11-20');
        $availabilities = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $dateStr = $date->toDateString();

            // Rest day on weekends
            if ($dayOfWeek === Carbon::SUNDAY || $dayOfWeek === Carbon::SATURDAY) {
                $this->addRestDay($availabilities, $doctor->id, $dateStr);
                continue;
            }

            // Mon, Wed, Fri: Duty at Davao Skin Specialists Clinic
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $doctor->id,
                    $clinicSpecialists,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            } else {
                // Tue, Thu: Duty at DermCare Center - Matina
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $doctor->id,
                    $clinicMatina,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            }
        }

        // Bulk insert in chunks of 500 records
        foreach (array_chunk($availabilities, 500) as $chunk) {
            DoctorAvailability::insert($chunk);
        }
    }

    /**
     * Add morning and afternoon duty hours with lunch break.
     */
    private function addDutyDayWithLunch(
        array &$records,
        int $doctorId,
        Clinic $clinic,
        string $dateStr,
        string $morningStart,
        string $morningEnd,
        string $lunchStart,
        string $lunchEnd,
        string $afternoonStart,
        string $afternoonEnd
    ): void {
        $now = now();

        // Morning Duty Shift
        $records[] = [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => $doctorId,
            'clinic_id' => $clinic->id,
            'location_name' => $clinic->name,
            'available_date' => $dateStr,
            'start_time' => $morningStart,
            'end_time' => $morningEnd,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Lunch Break (blocked / unavailable)
        $records[] = [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => $doctorId,
            'clinic_id' => null,
            'location_name' => 'Lunch Break',
            'available_date' => $dateStr,
            'start_time' => $lunchStart,
            'end_time' => $lunchEnd,
            'is_available' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Afternoon Duty Shift
        $records[] = [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => $doctorId,
            'clinic_id' => $clinic->id,
            'location_name' => $clinic->name,
            'available_date' => $dateStr,
            'start_time' => $afternoonStart,
            'end_time' => $afternoonEnd,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Add full-day rest day.
     */
    private function addRestDay(
        array &$records,
        int $doctorId,
        string $dateStr,
        string $label = 'Rest Day / Weekend'
    ): void {
        $now = now();

        $records[] = [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => $doctorId,
            'clinic_id' => null,
            'location_name' => $label,
            'available_date' => $dateStr,
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'is_available' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
