<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Diagnosis;

class DiagnosisController extends Controller
{
    public function diagnose(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        try {
            $image = $request->file('image');
            
            $response = Http::attach(
                'file', 
                file_get_contents($image->getRealPath()), 
                $image->getClientOriginalName()
            )->post('http://127.0.0.1:8000/predict');

            if ($response->failed()) {
                Log::error('AI Server Error: ' . $response->body());
                return response()->json([
                    'error' => 'The AI server is currently unavailable or returned an error.',
                    'details' => $response->json()
                ], 503);
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
                'status' => 'completed'
            ]);

            return response()->json([
                'id' => $diagnosis->uuid,
                'label' => $diagnosis->label,
                'confidence' => $diagnosis->confidence,
                'all_probabilities' => $diagnosis->probabilities,
                'image_url' => Storage::url($diagnosis->image_path),
                'created_at' => $diagnosis->created_at
            ]);

        } catch (\Exception $e) {
            Log::error('Diagnosis Error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error during diagnosis.'], 500);
        }
    }
}
