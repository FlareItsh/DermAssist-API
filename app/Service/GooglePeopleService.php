<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePeopleService
{
    /**
     * Fetch user profile details (DOB, gender, phone, address) from Google People API.
     *
     * @return array<string, mixed>
     */
    public function fetchProfileDetails(string $accessToken): array
    {
        $details = [];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(7)
                ->get('https://people.googleapis.com/v1/people/me', [
                    'personFields' => 'birthdays,genders,phoneNumbers,addresses',
                ]);

            if (! $response->successful()) {
                Log::warning('Google People API request returned status '.$response->status().': '.$response->body());

                return $details;
            }

            $data = $response->json();

            // 1. Birthdays
            if (! empty($data['birthdays']) && is_array($data['birthdays'])) {
                foreach ($data['birthdays'] as $birthday) {
                    $date = $birthday['date'] ?? null;
                    if ($date && ! empty($date['year']) && ! empty($date['month']) && ! empty($date['day'])) {
                        $details['date_of_birth'] = sprintf('%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day']);
                        break;
                    }
                }
            }

            // 2. Genders
            if (! empty($data['genders']) && is_array($data['genders'])) {
                foreach ($data['genders'] as $gender) {
                    $val = strtolower((string) ($gender['value'] ?? ''));
                    if (in_array($val, ['male', 'female'], true)) {
                        $details['gender'] = $val;
                        break;
                    } elseif ($val === 'other' || $val === 'unspecified') {
                        $details['gender'] = 'other';
                        break;
                    }
                }
            }

            // 3. Phone Numbers
            if (! empty($data['phoneNumbers']) && is_array($data['phoneNumbers'])) {
                foreach ($data['phoneNumbers'] as $phone) {
                    $raw = (string) ($phone['value'] ?? '');
                    $clean = preg_replace('/\D/', '', $raw);
                    if ($clean) {
                        if (str_starts_with($clean, '639') && strlen($clean) === 12) {
                            $clean = '0'.substr($clean, 2);
                        }
                        $details['contact_number'] = $clean;
                        break;
                    }
                }
            }

            // 4. Addresses
            if (! empty($data['addresses']) && is_array($data['addresses'])) {
                foreach ($data['addresses'] as $address) {
                    if (! empty($address['streetAddress'])) {
                        $details['street'] = trim((string) $address['streetAddress']);
                    }
                    if (! empty($address['city'])) {
                        $details['city'] = trim((string) $address['city']);
                    }
                    if (! empty($address['region'])) {
                        $details['province'] = trim((string) $address['region']);
                    }
                    if (! empty($address['country'])) {
                        $details['country'] = trim((string) $address['country']);
                    }
                    if (! empty($details['street']) || ! empty($details['city'])) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Error fetching Google People details: '.$e->getMessage());
        }

        return $details;
    }
}
