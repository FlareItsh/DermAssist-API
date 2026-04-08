<?php

namespace App\Http\Controllers;

use App\Service\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VerificationController extends Controller
{
    private VerificationService $verificationService;

    public function __construct(VerificationService $verificationService)
    {
        $this->verificationService = $verificationService;
    }

    public function index(Request $request)
    {
        return $this->verificationService->listVerification(
            $request->input('per_page', 15),
            $request->query('status'),
            $request->query('search')
        );
    }

    public function store(Request $request)
    {
        return $this->verificationService->createVerification($request->all());
    }

    public function show(string $uuid)
    {
        return $this->verificationService->getVerification($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->verificationService->updateVerification($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->verificationService->deleteVerification($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid)
    {
        return $this->verificationService->restoreVerification($uuid);
    }
}
