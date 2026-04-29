<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosisController extends Controller
{
    public function diagnose(Request $request)
    {
        if (! $request->hasFile('image')) {
            return response()->json(['error' => 'No image uploaded'], 400);
        }

        $image = $request->file('image');
        $pythonApiUrl = 'http://127.0.0.1:8001/predict';

        try {
            // Forward the image to the Python AI API
            $response = Http::attach(
                'file',
                file_get_contents($image->getRealPath()),
                $image->getClientOriginalName()
            )->post($pythonApiUrl);

            if ($response->failed()) {
                Log::error('AI API Error: '.$response->body());

                return response()->json([
                    'error' => 'AI Service Error',
                    'details' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('Diagnosis proxy error: '.$e->getMessage());

            return response()->json(['error' => 'Could not connect to AI service'], 500);
        }
    }
}
