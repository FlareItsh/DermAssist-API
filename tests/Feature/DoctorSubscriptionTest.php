<?php

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;

test('doctor can list active subscription plans', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    Plan::factory()->create(['is_active' => true, 'name' => 'Pro Plan']);

    $response = $this->actingAs($doctor)
        ->getJson('/api/subscription/plans');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['uuid', 'name', 'tier_type', 'price_monthly', 'price_annual'],
            ],
        ]);
});

test('doctor can view current subscription status', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);

    $response = $this->actingAs($doctor)
        ->getJson('/api/subscription/my-subscription');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'data' => ['subscription', 'invoices'],
        ]);
});
