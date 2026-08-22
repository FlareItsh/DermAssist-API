<?php

namespace Database\Seeders;

use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();
        $patientRole = Role::where('slug', 'patient')->first();
        $doctorRole = Role::where('slug', 'doctor')->first();
        $secretaryRole = Role::where('slug', 'secretary')->first();

        // 1. Admin Account
        User::firstOrCreate(
            ['email' => 'admin@dermassist.com'],
            [
                'first_name' => 'System',
                'last_name' => 'Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRole->id,
                'age' => 35,
                'gender' => 'Male',
                'street' => 'JP Laurel Avenue',
                'barangay' => 'Buhangin',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => 7.0850,
                'longitude' => 125.6130,
            ]
        );

        // 2. Main Patient Account
        User::firstOrCreate(
            ['email' => 'patient@dermassist.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Patient',
                'password' => Hash::make('password'),
                'role_id' => $patientRole->id,
                'age' => 28,
                'gender' => 'Male',
                'street' => 'McArthur Highway',
                'barangay' => 'Matina',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => 7.0620,
                'longitude' => 125.5940,
            ]
        );

        // Additional Hardcoded Patients
        $hardcodedPatients = [
            [
                'email' => 'maria.santos@example.com',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'age' => 32,
                'gender' => 'Female',
                'barangay' => 'Poblacion',
                'city' => 'Davao City',
            ],
            [
                'email' => 'carlo.reyes@example.com',
                'first_name' => 'Carlo',
                'last_name' => 'Reyes',
                'age' => 26,
                'gender' => 'Male',
                'barangay' => 'Agdao',
                'city' => 'Davao City',
            ],
            [
                'email' => 'elena.torres@example.com',
                'first_name' => 'Elena',
                'last_name' => 'Torres',
                'age' => 41,
                'gender' => 'Female',
                'barangay' => 'Toril',
                'city' => 'Davao City',
            ],
        ];

        foreach ($hardcodedPatients as $pat) {
            User::firstOrCreate(
                ['email' => $pat['email']],
                array_merge($pat, [
                    'password' => Hash::make('password'),
                    'role_id' => $patientRole->id,
                    'province' => 'Davao del Sur',
                    'country' => 'Philippines',
                    'latitude' => 7.0700,
                    'longitude' => 125.6000,
                ])
            );
        }

        // 3. Default Doctor Account (SUBSCRIBED DOCTOR)
        $mainDoctor = User::firstOrCreate(
            ['email' => 'doctor@dermassist.com'],
            [
                'first_name' => 'Allan',
                'last_name' => 'Smith',
                'password' => Hash::make('password'),
                'role_id' => $doctorRole->id,
                'prc_number' => 'PRC-0012345',
                'affiliation' => 'Davao Medical School Foundation Hospital',
                'age' => 45,
                'gender' => 'Male',
                'street' => 'E. Quirino Avenue',
                'barangay' => 'Poblacion',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => 7.0731,
                'longitude' => 125.6128,
            ]
        );

        // 4. Additional Hardcoded Doctors (UNSUBSCRIBED / VARIOUS STATUSES)
        $doctorProfiles = [
            [
                'email' => 'dr.beatriz.cruz@dermassist.com',
                'first_name' => 'Beatriz',
                'last_name' => 'Cruz',
                'prc_number' => 'PRC-0098765',
                'affiliation' => 'Southern Philippines Medical Center',
                'age' => 39,
                'gender' => 'Female',
                'barangay' => 'Bajada',
            ],
            [
                'email' => 'dr.ricardo.dizon@dermassist.com',
                'first_name' => 'Ricardo',
                'last_name' => 'Dizon',
                'prc_number' => 'PRC-0045678',
                'affiliation' => 'Davao Doctors Hospital',
                'age' => 52,
                'gender' => 'Male',
                'barangay' => 'Madrazo',
            ],
            [
                'email' => 'dr.clara.mendoza@dermassist.com',
                'first_name' => 'Clara',
                'last_name' => 'Mendoza',
                'prc_number' => 'PRC-0054321',
                'affiliation' => 'San Pedro Hospital of Davao',
                'age' => 36,
                'gender' => 'Female',
                'barangay' => 'Guzman',
            ],
        ];

        $createdDoctors = [$mainDoctor];

        foreach ($doctorProfiles as $docData) {
            $createdDoc = User::firstOrCreate(
                ['email' => $docData['email']],
                array_merge($docData, [
                    'password' => Hash::make('password'),
                    'role_id' => $doctorRole->id,
                    'street' => 'Main Avenue',
                    'city' => 'Davao City',
                    'province' => 'Davao del Sur',
                    'country' => 'Philippines',
                    'latitude' => 7.0750,
                    'longitude' => 125.6100,
                ])
            );
            $createdDoctors[] = $createdDoc;
        }

        // 5. Secretaries linked to Doctors
        User::firstOrCreate(
            ['email' => 'secretary@dermassist.com'],
            [
                'first_name' => 'Sarah',
                'last_name' => 'Secretary',
                'password' => Hash::make('password'),
                'role_id' => $secretaryRole->id,
                'doctor_id' => $mainDoctor->id,
                'age' => 30,
                'gender' => 'Female',
                'street' => 'E. Quirino Avenue',
                'barangay' => 'Poblacion',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => 7.0731,
                'longitude' => 125.6128,
            ]
        );

        // 6. Verify all doctors in DoctorVerification
        foreach ($createdDoctors as $doc) {
            DoctorVerification::firstOrCreate([
                'user_id' => $doc->id,
            ], [
                'prc_number' => $doc->prc_number,
                'status' => 'verified',
            ]);
        }
    }
}
