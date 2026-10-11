<?php

use App\Service\GooglePeopleService;
use Illuminate\Support\Facades\Http;

test('google people service parses birthday, gender, phone, and address correctly', function () {
    Http::fake([
        'https://people.googleapis.com/*' => Http::response([
            'birthdays' => [
                [
                    'date' => [
                        'year' => 1995,
                        'month' => 6,
                        'day' => 15,
                    ],
                ],
            ],
            'genders' => [
                ['value' => 'female'],
            ],
            'phoneNumbers' => [
                ['value' => '+63 912 345 6789'],
            ],
            'addresses' => [
                [
                    'streetAddress' => '123 Rizal St',
                    'city' => 'Manila',
                    'region' => 'Metro Manila',
                    'country' => 'Philippines',
                ],
            ],
        ], 200),
    ]);

    $service = new GooglePeopleService;
    $details = $service->fetchProfileDetails('fake-token');

    expect($details)->toBeArray()
        ->and($details['date_of_birth'])->toBe('1995-06-15')
        ->and($details['gender'])->toBe('female')
        ->and($details['contact_number'])->toBe('09123456789')
        ->and($details['street'])->toBe('123 Rizal St')
        ->and($details['city'])->toBe('Manila')
        ->and($details['province'])->toBe('Metro Manila')
        ->and($details['country'])->toBe('Philippines');
});

test('google people service handles incomplete or missing birthday year gracefully', function () {
    Http::fake([
        'https://people.googleapis.com/*' => Http::response([
            'birthdays' => [
                [
                    'date' => [
                        'month' => 6,
                        'day' => 15,
                    ],
                ],
            ],
            'genders' => [],
            'phoneNumbers' => [],
            'addresses' => [],
        ], 200),
    ]);

    $service = new GooglePeopleService;
    $details = $service->fetchProfileDetails('fake-token');

    expect($details)->not->toHaveKey('date_of_birth');
});

test('google people service handles api failure without throwing exceptions', function () {
    Http::fake([
        'https://people.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $service = new GooglePeopleService;
    $details = $service->fetchProfileDetails('invalid-token');

    expect($details)->toBeArray()->toBeEmpty();
});
