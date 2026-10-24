<?php

use App\Models\Medicine;
use App\Models\MedicineVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => RoleSeeder::class]);
});

test('doctor can only list their own medicines and not another doctors medicines', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctorA = User::factory()->create(['role_id' => $doctorRole->id]);
    $doctorB = User::factory()->create(['role_id' => $doctorRole->id]);

    // Doctor A medicine
    $medA = Medicine::create([
        'doctor_id' => $doctorA->id,
        'name' => 'Doctor A Hydrocortisone',
        'description' => 'Topical steroid',
    ]);
    MedicineVariant::create([
        'medicine_id' => $medA->id,
        'dosage' => '1',
        'unit' => '%',
        'default_frequency' => 'Apply BID',
    ]);

    // Doctor B medicine
    $medB = Medicine::create([
        'doctor_id' => $doctorB->id,
        'name' => 'Doctor B Tretinoin',
        'description' => 'Retinoid',
    ]);
    MedicineVariant::create([
        'medicine_id' => $medB->id,
        'dosage' => '0.05',
        'unit' => '%',
        'default_frequency' => 'Apply once daily',
    ]);

    Sanctum::actingAs($doctorA);

    $response = $this->getJson('/api/doctor/medicines');

    $response->assertStatus(200)
        ->assertJsonFragment(['name' => 'Doctor A Hydrocortisone'])
        ->assertJsonMissing(['name' => 'Doctor B Tretinoin']);
});

test('doctor can create medicine with dosage variants', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    Sanctum::actingAs($doctor);

    $payload = [
        'name' => 'Amoxicillin + Clavulanate',
        'description' => 'Broad-spectrum antibiotic',
        'variants' => [
            [
                'dosage' => '625',
                'unit' => 'mg',
                'default_frequency' => 'Every 12 hours with meals',
            ],
            [
                'dosage' => '1000',
                'unit' => 'mg',
                'default_frequency' => 'Every 12 hours',
            ],
        ],
    ];

    $response = $this->postJson('/api/doctor/medicines', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Amoxicillin + Clavulanate')
        ->assertJsonPath('data.doctor_id', $doctor->id)
        ->assertJsonCount(2, 'data.variants');

    $this->assertDatabaseHas('medicines', [
        'name' => 'Amoxicillin + Clavulanate',
        'doctor_id' => $doctor->id,
    ]);

    $this->assertDatabaseHas('medicine_variants', [
        'dosage' => '625',
        'unit' => 'mg',
        'default_frequency' => 'Every 12 hours with meals',
    ]);
});

test('doctor can update medicine and synchronize variants', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $medicine = Medicine::create([
        'doctor_id' => $doctor->id,
        'name' => 'Ciprofloxacin',
        'description' => 'Fluoroquinolone antibiotic',
    ]);

    $var1 = MedicineVariant::create([
        'medicine_id' => $medicine->id,
        'dosage' => '250',
        'unit' => 'mg',
        'default_frequency' => 'Every 12 hours',
    ]);

    $var2 = MedicineVariant::create([
        'medicine_id' => $medicine->id,
        'dosage' => '500',
        'unit' => 'mg',
        'default_frequency' => 'Every 12 hours',
    ]);

    Sanctum::actingAs($doctor);

    // Update var1, delete var2, add new var3
    $payload = [
        'name' => 'Ciprofloxacin HCl',
        'description' => 'Updated antibiotic description',
        'variants' => [
            [
                'uuid' => $var1->uuid,
                'dosage' => '250',
                'unit' => 'mg',
                'default_frequency' => 'Every 12 hours after meals',
            ],
            [
                'dosage' => '750',
                'unit' => 'mg',
                'default_frequency' => 'Once daily',
            ],
        ],
    ];

    $response = $this->putJson("/api/doctor/medicines/{$medicine->uuid}", $payload);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Ciprofloxacin HCl')
        ->assertJsonCount(2, 'data.variants');

    $this->assertDatabaseHas('medicine_variants', [
        'id' => $var1->id,
        'default_frequency' => 'Every 12 hours after meals',
    ]);

    $this->assertDatabaseMissing('medicine_variants', [
        'id' => $var2->id,
    ]);

    $this->assertDatabaseHas('medicine_variants', [
        'dosage' => '750',
        'unit' => 'mg',
    ]);
});

test('doctor cannot update another doctors medicine', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctorA = User::factory()->create(['role_id' => $doctorRole->id]);
    $doctorB = User::factory()->create(['role_id' => $doctorRole->id]);

    $doctorBMed = Medicine::create([
        'doctor_id' => $doctorB->id,
        'name' => 'Doctor B Custom Med',
    ]);
    MedicineVariant::create([
        'medicine_id' => $doctorBMed->id,
        'dosage' => '10',
        'unit' => 'mg',
        'default_frequency' => 'Daily',
    ]);

    Sanctum::actingAs($doctorA);

    $response = $this->putJson("/api/doctor/medicines/{$doctorBMed->uuid}", [
        'name' => 'Hacked Doctor B Med',
        'variants' => [['dosage' => '100', 'unit' => 'mg', 'default_frequency' => 'Daily']],
    ]);
    $response->assertStatus(403);
});

test('doctor can delete their own medicine', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $medicine = Medicine::create([
        'doctor_id' => $doctor->id,
        'name' => 'Fluconazole',
    ]);
    $variant = MedicineVariant::create([
        'medicine_id' => $medicine->id,
        'dosage' => '150',
        'unit' => 'mg',
        'default_frequency' => 'Single dose',
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->deleteJson("/api/doctor/medicines/{$medicine->uuid}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('medicines', ['id' => $medicine->id]);
    $this->assertDatabaseMissing('medicine_variants', ['id' => $variant->id]);
});
