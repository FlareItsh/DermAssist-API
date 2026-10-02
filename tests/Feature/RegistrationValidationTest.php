<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
});

test('registration requires strong password meeting all criteria', function () {
    // Missing uppercase
    $response1 = $this->postJson('/api/register', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'weak1@example.com',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $response1->assertStatus(422);
    $response1->assertJsonValidationErrors(['password']);

    // Missing numbers
    $response2 = $this->postJson('/api/register', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'weak2@example.com',
        'password' => 'Password!',
        'password_confirmation' => 'Password!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $response2->assertStatus(422);
    $response2->assertJsonValidationErrors(['password']);

    // Missing special symbol
    $response3 = $this->postJson('/api/register', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'weak3@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $response3->assertStatus(422);
    $response3->assertJsonValidationErrors(['password']);

    // Too short (< 8 chars)
    $response4 = $this->postJson('/api/register', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'weak4@example.com',
        'password' => 'Pass1!',
        'password_confirmation' => 'Pass1!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $response4->assertStatus(422);
    $response4->assertJsonValidationErrors(['password']);

    // Compliant strong password passes
    $responseOk = $this->postJson('/api/register', [
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'strong@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $responseOk->assertStatus(201);
});

test('registration validates name format and sanitizes tags', function () {
    // Numeric/invalid characters in name
    $response = $this->postJson('/api/register', [
        'firstName' => 'John123',
        'lastName' => 'Doe',
        'email' => 'namefail@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['firstName']);

    // Valid hyphenated and apostrophe names pass and are sanitized
    $responseOk = $this->postJson('/api/register', [
        'firstName' => '<b>Mary-Jane</b>',
        'middleName' => "O'Connor",
        'lastName' => 'Dela Cruz',
        'email' => 'MARY.JANE@EXAMPLE.COM ',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'patient',
        'agree_to_terms' => true,
    ]);
    $responseOk->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'first_name' => 'Mary-Jane',
        'middle_name' => "O'Connor",
        'last_name' => 'Dela Cruz',
        'email' => 'mary.jane@example.com',
    ]);
});

test('registration requires terms of service agreement', function () {
    $response = $this->postJson('/api/register', [
        'firstName' => 'NoTerms',
        'lastName' => 'User',
        'email' => 'noterms@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'patient',
        'agree_to_terms' => false,
    ]);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['agree_to_terms']);
});
