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
        $doctorRole = Role::where('slug', 'doctor')->first();
        $secretaryRole = Role::where('slug', 'secretary')->first();
        $patientRole = Role::where('slug', 'patient')->first();

        $defaultPassword = Hash::make('password');

        // 1. Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@derm.com'],
            [
                'first_name' => 'Sam',
                'last_name' => 'Ramos',
                'password' => $defaultPassword,
                'role_id' => $adminRole->id,
                'contact_number' => '+63 917 111 2233',
                'date_of_birth' => '1990-05-15',
                'age' => 36,
                'gender' => 'Male',
                'street' => 'J.P. Laurel Avenue, Bajada',
                'barangay' => 'Barangay 19-B',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'location' => 'Davao City, Philippines',
                'latitude' => 7.0850,
                'longitude' => 125.6130,
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // 2. Doctor (Created prior to Secretary to satisfy foreign key constraint)
        $doctor = User::updateOrCreate(
            ['email' => 'doc@derm.com'],
            [
                'first_name' => 'Ben',
                'last_name' => 'Cruz',
                'password' => $defaultPassword,
                'role_id' => $doctorRole->id,
                'prc_number' => 'PRC-0012345',
                'affiliation' => 'Davao Doctors Hospital',
                'contact_number' => '+63 918 222 3344',
                'date_of_birth' => '1985-08-20',
                'age' => 41,
                'gender' => 'Male',
                'street' => 'E. Quirino Avenue',
                'barangay' => 'Poblacion',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'location' => 'Davao City, Philippines',
                'latitude' => 7.0731,
                'longitude' => 125.6128,
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // Doctor Verification: Verified Status
        DoctorVerification::updateOrCreate(
            ['user_id' => $doctor->id],
            [
                'prc_number' => $doctor->prc_number,
                'status' => DoctorVerification::STATUS_VERIFIED,
            ]
        );

        // 3. Secretary (Related to Dr. Ben Cruz)
        $secretary = User::updateOrCreate(
            ['email' => 'sec@derm.com'],
            [
                'first_name' => 'Joy',
                'last_name' => 'Santos',
                'password' => $defaultPassword,
                'role_id' => $secretaryRole->id,
                'doctor_id' => $doctor->id,
                'contact_number' => '+63 920 333 4455',
                'date_of_birth' => '1998-03-12',
                'age' => 28,
                'gender' => 'Female',
                'street' => 'E. Quirino Avenue',
                'barangay' => 'Poblacion',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'location' => 'Davao City, Philippines',
                'latitude' => 7.0731,
                'longitude' => 125.6128,
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // 4. Patient
        $patient = User::updateOrCreate(
            ['email' => 'pat@derm.com'],
            [
                'first_name' => 'Dan',
                'last_name' => 'Diaz',
                'password' => $defaultPassword,
                'role_id' => $patientRole->id,
                'contact_number' => '+63 922 444 5566',
                'date_of_birth' => '2000-11-18',
                'age' => 25,
                'gender' => 'Male',
                'street' => 'McArthur Highway, Matina',
                'barangay' => 'Matina Crossing',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'country' => 'Philippines',
                'location' => 'Davao City, Philippines',
                'latitude' => 7.0620,
                'longitude' => 125.5940,
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );
    }
}
