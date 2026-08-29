<?php

use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;

test('doctor checkout generates paymongo gateway checkout url', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['is_active' => true]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/checkout', [
            'plan_uuid' => $plan->uuid,
            'billing_cycle' => 'monthly',
            'payment_method' => 'paymongo',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'status',
            'data' => ['checkout_url', 'invoice', 'subscription'],
        ]);

    expect($response->json('data.checkout_url'))->not->toBeNull();
});

test('webhook processes and automatically activates subscription', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['is_active' => true]);

    $subscription = Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'status' => 'trialing',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $invoice = PaymentInvoice::create([
        'subscription_id' => $subscription->id,
        'user_id' => $doctor->id,
        'amount' => 1499.00,
        'final_amount' => 1499.00,
        'payment_method' => 'paymongo',
        'payment_status' => 'pending',
    ]);

    $payload = [
        'data' => [
            'attributes' => [
                'data' => [
                    'attributes' => [
                        'reference_number' => $invoice->uuid,
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/paymongo', $payload);

    $response->assertStatus(200);

    expect($subscription->fresh()->status)->toBe('active');
    expect($invoice->fresh()->payment_status)->toBe('paid');
});
