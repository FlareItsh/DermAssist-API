<?php

namespace App\Http\Controllers;

use App\Service\MedicineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicineController extends Controller
{
    public function __construct(
        protected MedicineService $medicineService
    ) {}

    /**
     * List all medicines accessible to authenticated doctor/staff.
     */
    public function index(Request $request): JsonResponse
    {
        $medicines = $this->medicineService->listMedicines($request->user());

        return response()->json([
            'data' => $medicines,
        ]);
    }

    /**
     * Create a new medicine record with variants.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'variants' => 'required|array|min:1',
            'variants.*.dosage' => 'required|string|max:50',
            'variants.*.unit' => 'required|string|max:50',
            'variants.*.default_frequency' => 'required|string|max:255',
        ]);

        $medicine = $this->medicineService->createMedicine($request->user(), $validated);

        return response()->json([
            'message' => 'Medicine created successfully.',
            'data' => $medicine,
        ], 201);
    }

    /**
     * Show a single medicine with variants.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $medicine = $this->medicineService->getMedicine($request->user(), $uuid);

        return response()->json([
            'data' => $medicine,
        ]);
    }

    /**
     * Update an existing medicine record and synchronize variants.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'variants' => 'required|array|min:1',
            'variants.*.uuid' => 'nullable|string',
            'variants.*.dosage' => 'required|string|max:50',
            'variants.*.unit' => 'required|string|max:50',
            'variants.*.default_frequency' => 'required|string|max:255',
        ]);

        $medicine = $this->medicineService->updateMedicine($request->user(), $uuid, $validated);

        return response()->json([
            'message' => 'Medicine updated successfully.',
            'data' => $medicine,
        ]);
    }

    /**
     * Delete an existing medicine record.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->medicineService->deleteMedicine($request->user(), $uuid);

        return response()->json([
            'message' => 'Medicine deleted successfully.',
        ]);
    }
}
