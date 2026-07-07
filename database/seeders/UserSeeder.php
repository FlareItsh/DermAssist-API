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
                'street' => fake()->streetAddress(),
                'barangay' => 'Buhangin',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => fake()->latitude(7.05, 7.15),
                'longitude' => fake()->longitude(125.55, 125.65),
            ]
        );

        // 2. Patient Account
        User::firstOrCreate(
            ['email' => 'patient@dermassist.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Patient',
                'password' => Hash::make('password'),
                'role_id' => $patientRole->id,
                'age' => 28,
                'gender' => 'Male',
                'street' => fake()->streetAddress(),
                'barangay' => 'Matina',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => fake()->latitude(7.05, 7.15),
                'longitude' => fake()->longitude(125.55, 125.65),
            ]
        );

        // 3. Doctor Account
        User::firstOrCreate(
            ['email' => 'doctor@dermassist.com'],
            [
                'first_name' => 'Allan',
                'last_name' => 'Smith',
                'password' => Hash::make('password'),
                'role_id' => $doctorRole->id,
                'prc_number' => '1234567',
                'affiliation' => 'Davao Medical School Foundation',
                'age' => 45,
                'gender' => 'Female',
                'street' => fake()->streetAddress(),
                'barangay' => 'Obrero',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'latitude' => fake()->latitude(7.05, 7.15),
                'longitude' => fake()->longitude(125.55, 125.65),
            ]
        );

        // 4. Multiple Patients
        User::factory()->count(10)->create([
            'role_id' => $patientRole->id,
            'password' => Hash::make('password'),
        ]);

        // 5. Multiple Doctors
        User::factory()->count(10)->create([
            'role_id' => $doctorRole->id,
            'password' => Hash::make('password'),
            'prc_number' => fn () => fake()->numerify('#######'),
            'affiliation' => fn () => fake()->company(),
        ]);

        // 6. Verify all doctors
        $doctors = User::where('role_id', $doctorRole->id)->get();
        foreach ($doctors as $doc) {
            DoctorVerification::firstOrCreate([
                'user_id' => $doc->id,
            ], [
                'prc_number' => $doc->prc_number,
                'status' => 'verified',
            ]);
        }
    }
}
