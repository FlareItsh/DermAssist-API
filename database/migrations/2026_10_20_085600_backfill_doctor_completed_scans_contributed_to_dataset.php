<?php

use App\Models\Diagnosis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $diagnoses = Diagnosis::query()
            ->whereNotNull('doctor_uuid')
            ->whereNotNull('image_path')
            ->whereIn(DB::raw('LOWER(label)'), ['acne', 'eczema', 'herpes'])
            ->where('status', 'completed')
            ->get();

        foreach ($diagnoses as $diag) {
            $category = Str::slug($diag->label);
            $sourcePath = $diag->image_path;

            if ($sourcePath && Storage::disk('public')->exists($sourcePath)) {
                $datasetPath = 'dataset/'.$category.'/'.basename($sourcePath);
                if (! Storage::disk('public')->exists($datasetPath)) {
                    try {
                        Storage::disk('public')->copy($sourcePath, $datasetPath);
                    } catch (Throwable $e) {
                        // ignore copy failures
                    }
                }
            }

            $diag->update([
                'contributed_to_dataset' => true,
                'contributed_at' => $diag->contributed_at ?? now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation needed for historical flag backfill
    }
};
