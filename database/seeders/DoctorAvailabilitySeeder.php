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

        // 5. Seed 14-Day Duty Schedules
        $now = Carbon::today();

        for ($dayOffset = 0; $dayOffset <= 14; $dayOffset++) {
            $targetDate = $now->copy()->addDays($dayOffset);
            $dayOfWeek = $targetDate->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $dateStr = $targetDate->toDateString();

            // Sunday is off-duty for all doctors
            if ($dayOfWeek === Carbon::SUNDAY) {
                foreach ([$smith, $beatriz, $ricardo, $clara, $miguel] as $doc) {
                    DoctorAvailability::create([
                        'uuid' => (string) Str::uuid(),
                        'doctor_id' => $doc->id,
                        'clinic_id' => null,
                        'location_name' => 'Rest Day / Off-Duty',
                        'available_date' => $dateStr,
                        'start_time' => '00:00',
                        'end_time' => '23:59',
                        'is_available' => false,
                    ]);
                }

                continue;
            }

            // --- Dr. Allan Smith (Practice across his 3 clinic branches) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                // Morning at Makati, Afternoon at BGC
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $smith->id,
                    'clinic_id' => $smithMakati->id,
                    'location_name' => $smithMakati->name,
                    'available_date' => $dateStr,
                    'start_time' => '09:00',
                    'end_time' => '12:30',
                    'is_available' => true,
                ]);
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $smith->id,
                    'clinic_id' => null,
                    'location_name' => 'Transit & Lunch Break',
                    'available_date' => $dateStr,
                    'start_time' => '12:30',
                    'end_time' => '14:00',
                    'is_available' => false,
                ]);
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $smith->id,
                    'clinic_id' => $smithBgc->id,
                    'location_name' => $smithBgc->name,
                    'available_date' => $dateStr,
                    'start_time' => '14:00',
                    'end_time' => '18:00',
                    'is_available' => true,
                ]);
            } else {
                // Tue / Thu / Sat at Davao Clinic
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $smith->id,
                    'clinic_id' => $smithDavao->id,
                    'location_name' => $smithDavao->name,
                    'available_date' => $dateStr,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                    'is_available' => true,
                ]);
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $smith->id,
                    'clinic_id' => null,
                    'location_name' => 'Lunch Break',
                    'available_date' => $dateStr,
                    'start_time' => '12:00',
                    'end_time' => '13:00',
                    'is_available' => false,
                ]);
            }

            // --- Dr. Beatriz Cruz (Owned: Cruz Clinic, Associate at: Smith Makati) ---
            if (in_array($dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY])) {
                // Full day at her own Cruz Skin Clinic
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $beatriz->id,
                    'clinic_id' => $cruzClinic->id,
                    'location_name' => $cruzClinic->name,
                    'available_date' => $dateStr,
                    'start_time' => '08:30',
                    'end_time' => '16:30',
                    'is_available' => true,
                ]);
            } else {
                // Tue / Thu / Sat on duty as Associate at Smith Makati Center
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $beatriz->id,
                    'clinic_id' => $smithMakati->id,
                    'location_name' => $smithMakati->name,
                    'available_date' => $dateStr,
                    'start_time' => '09:00',
                    'end_time' => '16:00',
                    'is_available' => true,
                ]);
            }

            // --- Dr. Ricardo Dizon (Owned: Dizon Clinic, Consultant at: Smith BGC) ---
            if (in_array($dayOfWeek, [Carbon::TUESDAY, Carbon::THURSDAY])) {
                // Consultant duty at Smith BGC
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $ricardo->id,
                    'clinic_id' => $smithBgc->id,
                    'location_name' => $smithBgc->name,
                    'available_date' => $dateStr,
                    'start_time' => '13:00',
                    'end_time' => '18:00',
                    'is_available' => true,
                ]);
            } else {
                // Remaining weekdays at his own clinic
                DoctorAvailability::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $ricardo->id,
                    'clinic_id' => $dizonClinic->id,
                    'location_name' => $dizonClinic->name,
                    'available_date' => $dateStr,
                    'start_time' => '09:00',
                    'end_time' => '16:00',
                    'is_available' => true,
                ]);
            }

            // --- Dr. Clara Mendoza (Resident at Smith Davao & Mendoza Clinic) ---
            DoctorAvailability::create([
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $clara->id,
                'clinic_id' => $smithDavao->id,
                'location_name' => $smithDavao->name,
                'available_date' => $dateStr,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'is_available' => true,
            ]);

            // --- Dr. Miguel Tan (Strictly Solo: Only 1 clinic branch) ---
            DoctorAvailability::create([
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $miguel->id,
                'clinic_id' => $tanClinic->id,
                'location_name' => $tanClinic->name,
                'available_date' => $dateStr,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'is_available' => true,
            ]);
            DoctorAvailability::create([
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $miguel->id,
                'clinic_id' => null,
                'location_name' => 'Lunch Break',
                'available_date' => $dateStr,
                'start_time' => '12:00',
                'end_time' => '13:00',
                'is_available' => false,
            ]);
        }

        $this->command->info('Successfully seeded doctor clinics and realistic 14-day multi-doctor availability presets.');
    }
}
