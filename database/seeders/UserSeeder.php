<?php

namespace Database\Seeders;

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
            ]
        );

        // 3. Doctor Account
        User::firstOrCreate(
            ['email' => 'doctor@dermassist.com'],
            [
                'first_name' => 'Dr.',
                'last_name' => 'Smith',
                'password' => Hash::make('password'),
                'role_id' => $doctorRole->id,
            ]
        );
    }
}
