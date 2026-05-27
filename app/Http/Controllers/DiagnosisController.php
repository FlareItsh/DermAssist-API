<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Diagnosis;
use App\Service\DoctorAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DiagnosisController extends Controller
{
    public function diagnose(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
            'doctor_id' => 'nullable|exists:users,id',
        ]);

        try {
            $image = $request->file('image');
            $aiServerUrl = env('AI_SERVER_URL', 'http://127.0.0.1:8001');

            $response = Http::timeout(120)->attach(
                'file',
                file_get_contents($image->getRealPath()),
                $image->getClientOriginalName()
            )->post("{$aiServerUrl}/predict");

            if ($response->failed()) {
                Log::error('AI Server Error: '.$response->body());

                return response()->json([
                    'error' => 'The AI server is currently unavailable or returned an error.',
                    'details' => $response->json(),
                ], 503)->header('Access-Control-Allow-Origin', '*');
            }

            $aiResult = $response->json();

            // 1. Save image to public storage
            $path = $image->store('diagnoses', 'public');

            // 2. Create database record
            $diagnosis = Diagnosis::create([
                'user_uuid' => $request->header('X-User-Uuid') ?? $request->input('user_uuid'),
                'image_path' => $path,
                'label' => $aiResult['label'],
                'confidence' => $aiResult['confidence'],
                'probabilities' => $aiResult['all_probabilities'],
                'status' => 'completed',
            ]);

            $availabilityData = null;
            if ($request->has('doctor_id')) {
                $availabilityService = app(DoctorAvailabilityService::class);
                $availabilityCheck = $availabilityService->checkDoctorAvailability(
                    $request->input('doctor_id'),
                    now(),
                    $request->user()
                );

                if (! $availabilityCheck['is_available']) {
                    $availabilityData = [
                        'is_available' => false,
                        'next_available' => $availabilityCheck['next_available'],
                        'alternatives' => UserResource::collection($availabilityCheck['alternatives']),
                    ];
                } else {
                    $availabilityData = [
                        'is_available' => true,
                    ];
                }
            }

            $responsePayload = [
                'id' => $diagnosis->uuid,
                'label' => $diagnosis->label,
                'confidence' => $diagnosis->confidence,
                'all_probabilities' => $diagnosis->probabilities,
                'image_url' => Storage::url($diagnosis->image_path),
                'flagged_for_collection' => $aiResult['flagged_for_collection'] ?? false,
                'created_at' => $diagnosis->created_at,
            ];

            if ($availabilityData !== null) {
                $responsePayload['doctor_availability'] = $availabilityData;
            }

            return response()->json($responsePayload)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Diagnosis Error: '.$e->getMessage());

            return response()->json(['error' => 'Internal Server Error during diagnosis.'], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    public function collect(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
            'label' => 'required|string',
        ]);

        $image = $request->file('image');
        $aiServerUrl = env('AI_SERVER_URL', 'http://127.0.0.1:8001');

        $response = Http::attach(
            'file',
            file_get_contents($image->getRealPath()),
            $image->getClientOriginalName()
        )->post("{$aiServerUrl}/collect", [
            'label' => $request->input('label'),
        ]);

        return $response->json();
    }
}
