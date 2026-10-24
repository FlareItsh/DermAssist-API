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
        // 1. Fetch Doctor Account(s)
        $doctors = User::where('email', 'doc@derm.com')->get();
        if ($doctors->isEmpty()) {
            $fallbackDoctor = User::where('email', 'ben@derm.com')->first();
            if ($fallbackDoctor) {
                $doctors = collect([$fallbackDoctor]);
            } else {
                $doctors = User::whereHas('role', fn ($q) => $q->where('slug', 'doctor'))->get();
            }
        }

        if ($doctors->isEmpty()) {
            $this->command?->warn('No doc@derm.com doctor account found to seed availability.');

            return;
        }

        foreach ($doctors as $doctor) {
            // 2. Fetch or create doctor clinics in Davao City
            $clinicSpecialists = Clinic::firstOrCreate(
                [
                    'owner_doctor_id' => $doctor->id,
                    'name' => 'Davao Skin Specialists Clinic',
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'address' => 'Room 302, Medical Arts Building, Davao Doctors Hospital, E. Quirino Ave, Davao City',
                    'phone' => '+63 (82) 222-8000',
                    'email' => 'davao.specialists@derm.com',
                    'geo_latitude' => 7.0682,
                    'geo_longitude' => 125.6075,
                    'is_active' => true,
                ]
            );

            $clinicMatina = Clinic::firstOrCreate(
                [
                    'owner_doctor_id' => $doctor->id,
                    'name' => 'DermCare Center - Matina',
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'address' => '2nd Floor, Matina Town Square, McArthur Highway, Matina, Davao City',
                    'phone' => '+63 (82) 297-5000',
                    'email' => 'matina.care@derm.com',
                    'geo_latitude' => 7.0585,
                    'geo_longitude' => 125.5905,
                    'is_active' => true,
                ]
            );

            // 3. Clear existing availabilities for this doctor to prevent duplicate slots
            DoctorAvailability::where('doctor_id', $doctor->id)->delete();

            // 4. Seed Schedule from September 1, 2026 through December 31, 2026
            $startDate = Carbon::parse('2026-09-01');
            $endDate = Carbon::parse('2026-12-31');
            $availabilities = [];

            // Explicit full-day blocked dates (Holidays, Conventions, Medical Leaves)
            $fullDayBlocks = [
                '2026-11-06' => 'Annual Dermatological Society Convention',
                '2026-11-30' => 'Bonifacio Day (National Holiday)',
                '2026-12-08' => 'Special Non-Working Holiday',
                '2026-12-24' => 'Christmas Eve / Clinic Closed',
                '2026-12-25' => 'Christmas Day (Public Holiday)',
                '2026-12-30' => 'Rizal Day (National Holiday)',
                '2026-12-31' => "New Year's Eve / Year-End Inventory",
            ];

            // Explicit partial-day blocked dates (Hospital Board Meetings, Clinical Symposia)
            $partialDayBlocks = [
                '2026-11-18' => [
                    'clinic' => $clinicSpecialists,
                    'morning_start' => '09:00:00',
                    'morning_end' => '12:00:00',
                    'lunch_start' => '12:00:00',
                    'lunch_end' => '13:00:00',
                    'block_start' => '13:00:00',
                    'block_end' => '17:00:00',
                    'block_reason' => 'Hospital Medical Board Meeting',
                ],
                '2026-12-16' => [
                    'clinic' => $clinicSpecialists,
                    'morning_start' => '09:00:00',
                    'morning_end' => '12:00:00',
                    'lunch_start' => '12:00:00',
                    'lunch_end' => '13:00:00',
                    'afternoon_duty_start' => '13:00:00',
                    'afternoon_duty_end' => '15:00:00',
                    'block_start' => '15:00:00',
                    'block_end' => '17:00:00',
                    'block_reason' => 'Clinical Research Symposium',
                ],
            ];

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
                $dateStr = $date->toDateString();

                // 1. Check for whole-day blocked holidays / conventions
                if (isset($fullDayBlocks[$dateStr])) {
                    $this->addFullDayBlock($availabilities, $doctor->id, $dateStr, $fullDayBlocks[$dateStr]);
                    continue;
                }

                // 2. Rest day on weekends (Saturday / Sunday)
                if ($dayOfWeek === Carbon::SUNDAY || $dayOfWeek === Carbon::SATURDAY) {
                    $this->addRestDay($availabilities, $doctor->id, $dateStr);
                    continue;
                }

                // 3. Check for specific partial-day blocked dates
                if (isset($partialDayBlocks[$dateStr])) {
                    $config = $partialDayBlocks[$dateStr];
                    $this->addPartialDutyDay(
                        $availabilities,
                        $doctor->id,
                        $config['clinic'],
                        $dateStr,
                        $config
                    );
                    continue;
                }

                // 4. Regular Weekday Clinic Duty:
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

            $dutyCount = count(array_filter($availabilities, fn ($r) => $r['is_available']));
            $blockedCount = count(array_filter($availabilities, fn ($r) => ! $r['is_available']));

            $this->command?->info(sprintf(
                'Seeded %d duty shifts and %d blocked periods for doctor: %s (%s)',
                $dutyCount,
                $blockedCount,
                $doctor->email,
                $doctor->first_name.' '.$doctor->last_name
            ));
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
     * Add partial duty day with dedicated blocked period.
     */
    private function addPartialDutyDay(
        array &$records,
        int $doctorId,
        Clinic $clinic,
        string $dateStr,
        array $config
    ): void {
        $now = now();

        // Morning Duty Shift
        if (! empty($config['morning_start']) && ! empty($config['morning_end'])) {
            $records[] = [
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $doctorId,
                'clinic_id' => $clinic->id,
                'location_name' => $clinic->name,
                'available_date' => $dateStr,
                'start_time' => $config['morning_start'],
                'end_time' => $config['morning_end'],
                'is_available' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Lunch Break
        if (! empty($config['lunch_start']) && ! empty($config['lunch_end'])) {
            $records[] = [
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $doctorId,
                'clinic_id' => null,
                'location_name' => 'Lunch Break',
                'available_date' => $dateStr,
                'start_time' => $config['lunch_start'],
                'end_time' => $config['lunch_end'],
                'is_available' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Optional Afternoon Duty Shift prior to block
        if (! empty($config['afternoon_duty_start']) && ! empty($config['afternoon_duty_end'])) {
            $records[] = [
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $doctorId,
                'clinic_id' => $clinic->id,
                'location_name' => $clinic->name,
                'available_date' => $dateStr,
                'start_time' => $config['afternoon_duty_start'],
                'end_time' => $config['afternoon_duty_end'],
                'is_available' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Blocked Period
        if (! empty($config['block_start']) && ! empty($config['block_end'])) {
            $records[] = [
                'uuid' => (string) Str::uuid(),
                'doctor_id' => $doctorId,
                'clinic_id' => null,
                'location_name' => $config['block_reason'] ?? 'Blocked / Away',
                'available_date' => $dateStr,
                'start_time' => $config['block_start'],
                'end_time' => $config['block_end'],
                'is_available' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
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

    /**
     * Add full-day blocked / leave date (e.g. convention, holiday).
     */
    private function addFullDayBlock(
        array &$records,
        int $doctorId,
        string $dateStr,
        string $reason
    ): void {
        $now = now();

        $records[] = [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => $doctorId,
            'clinic_id' => null,
            'location_name' => $reason,
            'available_date' => $dateStr,
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'is_available' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
