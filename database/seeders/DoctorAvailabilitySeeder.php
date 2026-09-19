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
        // 1. Fetch doctors
        $smith = User::where('email', 'doctor@dermassist.com')->first();
        $beatriz = User::where('email', 'dr.beatriz.cruz@dermassist.com')->first();
        $ricardo = User::where('email', 'dr.ricardo.dizon@dermassist.com')->first();
        $clara = User::where('email', 'dr.clara.mendoza@dermassist.com')->first();
        $miguel = User::where('email', 'dr.miguel.tan@dermassist.com')->first();

        // 2. Clean old test clinics, memberships, and availabilities
        DB::table('doctor_availabilities')->delete();
        DB::table('clinic_doctors')->delete();
        DB::table('clinics')->delete();

        // 3. Create Clinics respecting max_clinics for each doctor
        // Dr. Allan Smith -> 3 Clinics (Clinic Group Plan: max_clinics = 3)
        $smithMakati = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $smith->id,
            'name' => 'Smith Dermatology & Laser Center - Makati',
            'address' => 'Suite 405, Makati Medical Plaza, Amorsolo St, Makati City',
            'phone' => '+63 (02) 8888-2300',
            'email' => 'makati.clinic@dermassist.ph',
            'geo_latitude' => 14.5547,
            'geo_longitude' => 121.0160,
            'is_active' => true,
        ]);

        $smithBgc = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $smith->id,
            'name' => 'Smith Skin & Aesthetics - BGC',
            'address' => 'Level 3, High Street South Corporate Plaza, BGC, Taguig City',
            'phone' => '+63 (02) 8789-7700',
            'email' => 'bgc.clinic@dermassist.ph',
            'geo_latitude' => 14.5492,
            'geo_longitude' => 121.0475,
            'is_active' => true,
        ]);

        $smithDavao = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $smith->id,
            'name' => 'Smith Derma Suite - Davao',
            'address' => 'Room 208, Davao Medical School Foundation Hospital, Davao City',
            'phone' => '+63 (82) 221-5000',
            'email' => 'davao.clinic@dermassist.ph',
            'geo_latitude' => 7.0731,
            'geo_longitude' => 125.6128,
            'is_active' => true,
        ]);

        // Dr. Beatriz Cruz -> 1 Clinic (Individual Plan: max_clinics = 1)
        $cruzClinic = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $beatriz->id,
            'name' => 'Cruz Skin Clinic - SPMC Suite',
            'address' => 'Southern Philippines Medical Center, Bajada, Davao City',
            'phone' => '+63 (82) 227-4000',
            'email' => 'cruz.derma@dermassist.ph',
            'geo_latitude' => 7.0910,
            'geo_longitude' => 125.6170,
            'is_active' => true,
        ]);

        // Dr. Ricardo Dizon -> 1 Clinic (Individual Plan: max_clinics = 1)
        $dizonClinic = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $ricardo->id,
            'name' => 'Dizon Dermatology & Wound Care - Madrazo',
            'address' => 'Madrazo Compound, Davao City',
            'phone' => '+63 (82) 222-3344',
            'email' => 'dizon.care@dermassist.ph',
            'geo_latitude' => 7.0820,
            'geo_longitude' => 125.6090,
            'is_active' => true,
        ]);

        // Dr. Clara Mendoza -> 1 Clinic (Unsubscribed: max_clinics = 1)
        $mendozaClinic = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $clara->id,
            'name' => 'Mendoza Skin Health Clinic - San Pedro',
            'address' => 'San Pedro Hospital Medical Arts, Davao City',
            'phone' => '+63 (82) 222-5566',
            'email' => 'clara.mendoza@dermassist.ph',
            'geo_latitude' => 7.0690,
            'geo_longitude' => 125.6080,
            'is_active' => true,
        ]);

        // Dr. Miguel Tan -> 1 Clinic (Individual Plan: max_clinics = 1, strictly solo)
        $tanClinic = Clinic::create([
            'uuid' => (string) Str::uuid(),
            'owner_doctor_id' => $miguel->id,
            'name' => 'Tan Derma Clinic - Bajada',
            'address' => 'Bajada Commercial Plaza, Davao City',
            'phone' => '+63 (82) 299-1122',
            'email' => 'tan.derma@dermassist.ph',
            'geo_latitude' => 7.0880,
            'geo_longitude' => 125.6140,
            'is_active' => true,
        ]);

        // 4. Seed Associate Doctor Seat Delegations into clinic_doctors
        // Dr. Beatriz Cruz (Personal Individual Plan) -> Associate at Smith Makati
        DB::table('clinic_doctors')->insert([
            'clinic_id' => $smithMakati->id,
            'doctor_user_id' => $beatriz->id,
            'role' => 'associate',
            'status' => 'active',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        // Dr. Ricardo Dizon (Past Due Personal Plan) -> Consultant at Smith BGC
        DB::table('clinic_doctors')->insert([
            'clinic_id' => $smithBgc->id,
            'doctor_user_id' => $ricardo->id,
            'role' => 'consultant',
            'status' => 'active',
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        // Dr. Clara Mendoza (Unsubscribed) -> Resident at Smith Davao
        DB::table('clinic_doctors')->insert([
            'clinic_id' => $smithDavao->id,
            'doctor_user_id' => $clara->id,
            'role' => 'resident',
            'status' => 'active',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // 5. Seed Regular Schedules from September 1, 2026 until December 31, 2026
        $startDate = Carbon::parse('2026-09-01');
        $endDate = Carbon::parse('2026-12-31');
        $availabilities = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $dateStr = $date->toDateString();

            // Rest days: Weekends (Saturday and Sunday) for all doctors
            if ($dayOfWeek === Carbon::SUNDAY || $dayOfWeek === Carbon::SATURDAY) {
                foreach ([$smith, $beatriz, $ricardo, $clara, $miguel] as $doc) {
                    $this->addRestDay($availabilities, $doc->id, $dateStr);
                }

                continue;
            }

            // --- 1. Dr. Allan Smith (Rotates across his 3 clinic branches) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY])) {
                // Makati Clinic on Mon / Wed
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $smith->id,
                    $smithMakati,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            } elseif (in_array($dayOfWeek, [Carbon::TUESDAY, Carbon::THURSDAY])) {
                // BGC Clinic on Tue / Thu
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $smith->id,
                    $smithBgc,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            } else {
                // Davao Suite on Friday
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $smith->id,
                    $smithDavao,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            }

            // --- 2. Dr. Beatriz Cruz (Owned: Cruz Clinic, Associate at: Smith Makati) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                // Own Clinic: Cruz Skin Clinic - SPMC Suite
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $beatriz->id,
                    $cruzClinic,
                    $dateStr,
                    '08:30:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '16:30:00'
                );
            } else {
                // Associate Duty at Smith Makati Center
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $beatriz->id,
                    $smithMakati,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '16:00:00'
                );
            }

            // --- 3. Dr. Ricardo Dizon (Owned: Dizon Clinic, Consultant at: Smith BGC) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                // Own Clinic: Dizon Dermatology & Wound Care
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $ricardo->id,
                    $dizonClinic,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '16:00:00'
                );
            } else {
                // Consultant Duty at Smith BGC
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $ricardo->id,
                    $smithBgc,
                    $dateStr,
                    '09:00:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '17:00:00'
                );
            }

            // --- 4. Dr. Clara Mendoza (Owned: Mendoza Clinic, Resident at: Smith Davao) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                // Own Clinic: Mendoza Skin Health Clinic
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $clara->id,
                    $mendozaClinic,
                    $dateStr,
                    '08:30:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '16:30:00'
                );
            } else {
                // Resident Duty at Smith Davao
                $this->addDutyDayWithLunch(
                    $availabilities,
                    $clara->id,
                    $smithDavao,
                    $dateStr,
                    '08:30:00',
                    '12:00:00',
                    '12:00:00',
                    '13:00:00',
                    '13:00:00',
                    '16:00:00'
                );
            }

            // --- 5. Dr. Miguel Tan (Strictly Solo Practice: Tan Derma Clinic) ---
            $this->addDutyDayWithLunch(
                $availabilities,
                $miguel->id,
                $tanClinic,
                $dateStr,
                '09:00:00',
                '12:00:00',
                '12:00:00',
                '13:00:00',
                '13:00:00',
                '17:00:00'
            );
        }

        // Bulk insert in chunks of 500 records
        foreach (array_chunk($availabilities, 500) as $chunk) {
            DoctorAvailability::insert($chunk);
        }

        $this->command->info(sprintf(
            'Successfully seeded doctor clinics and %d regular availability records through December 2026.',
            count($availabilities)
        ));
    }

    /**
     * Add morning and afternoon duty hours with a dedicated lunch break in between.
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

        // Morning Duty Shift (with clinic)
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

        // Afternoon Duty Shift (with clinic)
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
     * Add a full-day rest day / off-duty record.
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
