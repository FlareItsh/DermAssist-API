<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = \App\Models\Role::where('slug', 'admin')->first();
        $patientRole = \App\Models\Role::where('slug', 'patient')->first();
        $doctorRole = \App\Models\Role::where('slug', 'doctor')->first();

        // 1. Admin Account
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@dermassist.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRole->id,
            ]
        );

        // 2. Patient Account
        \App\Models\User::firstOrCreate(
            ['email' => 'patient@dermassist.com'],
            [
                'name' => 'John Patient',
                'password' => Hash::make('password'),
                'role_id' => $patientRole->id,
            ]
        );

        // 3. Doctor Account
        \App\Models\User::firstOrCreate(
            ['email' => 'doctor@dermassist.com'],
            [
                'name' => 'Dr. Smith',
                'password' => Hash::make('password'),
                'role_id' => $doctorRole->id,
            ]
        );
    }
}
