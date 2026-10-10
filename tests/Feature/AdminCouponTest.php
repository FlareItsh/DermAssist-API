<?php

use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
});

test('admin can create, view, and update coupon with limits and eligibility', function () {
    $admin = User::factory()->create([
        'role_id' => Role::where('slug', 'admin')->first()->id,
    ]);

    // Create
    $response = $this->actingAs($admin)->postJson('/api/admin/coupons', [
        'code' => 'save50',
        'discount_type' => 'percentage',
        'value' => 50,
        'duration' => 'once',
        'valid_from' => now()->toIso8601String(),
        'valid_until' => now()->addDays(30)->toIso8601String(),
        'max_redemptions' => 100,
        'max_redemptions_per_user' => 1,
        'min_spend' => 500,
        'applicable_plans' => [1, 2],
        'customer_segment' => 'new_doctors',
        'is_active' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.code', 'SAVE50')
        ->assertJsonPath('data.max_redemptions_per_user', 1)
        ->assertJsonPath('data.min_spend', 500);

    $couponId = $response->json('data.id');

    // Show
    $showResponse = $this->actingAs($admin)->getJson("/api/admin/coupons/{$couponId}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.code', 'SAVE50')
        ->assertJsonPath('data.customer_segment', 'new_doctors');

    // Update
    $updateResponse = $this->actingAs($admin)->putJson("/api/admin/coupons/{$couponId}", [
        'max_redemptions' => 200,
        'max_redemptions_per_user' => 2,
        'min_spend' => 1000,
        'is_active' => false,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.max_redemptions', 200)
        ->assertJsonPath('data.max_redemptions_per_user', 2)
        ->assertJsonPath('data.min_spend', 1000)
        ->assertJsonPath('data.is_active', false);
});

test('unused coupon can be permanently deleted', function () {
    $admin = User::factory()->create([
        'role_id' => Role::where('slug', 'admin')->first()->id,
    ]);

    $coupon = Coupon::create([
        'code' => 'TEMPTEST',
        'discount_type' => 'fixed_amount',
        'value' => 100,
        'times_redeemed' => 0,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/admin/coupons/{$coupon->id}?force=true");
    $response->assertStatus(200)
        ->assertJsonPath('permanent', true);

    $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
});

test('redeemed coupon falls back to archiving and cannot be hard deleted', function () {
    $admin = User::factory()->create([
        'role_id' => Role::where('slug', 'admin')->first()->id,
    ]);

    $coupon = Coupon::create([
        'code' => 'USEDCOUPON',
        'discount_type' => 'percentage',
        'value' => 20,
        'times_redeemed' => 5,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/admin/coupons/{$coupon->id}?force=true");
    $response->assertStatus(200)
        ->assertJsonPath('status', 'warning')
        ->assertJsonPath('archived', true);

    // Verify still in database but soft-deleted
    $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);

    // Restore test
    $restoreResponse = $this->actingAs($admin)->postJson("/api/admin/coupons/{$coupon->id}/restore");
    $restoreResponse->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertNotSoftDeleted('coupons', ['id' => $coupon->id]);
});
