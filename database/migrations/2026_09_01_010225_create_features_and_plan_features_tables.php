<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_has_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->boolean('is_included')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'feature_id']);
        });

        // Seed standard features
        $defaultFeatures = [
            [
                'name' => 'Show in Patient Scan Recommendations',
                'code' => 'show_in_recommendation',
                'description' => 'Featured prominently in patient nearby dermatologists recommendations after scan.',
                'sort_order' => 1,
            ],
            [
                'name' => 'Allow Doctor AI Scan Execution',
                'code' => 'can_execute_scan',
                'description' => 'Unlocks live skin disease scanning and instant diagnosis inference for doctors.',
                'sort_order' => 2,
            ],
            [
                'name' => 'Allow PDF Clinical Report Exports',
                'code' => 'export_pdf_reports',
                'description' => 'Enables downloading and printing full clinical assessment reports in PDF.',
                'sort_order' => 3,
            ],
            [
                'name' => 'Enable Teleconsultation Appointments',
                'code' => 'unlimited_appointments',
                'description' => 'Allows patients to book online and in-clinic consultation appointment slots.',
                'sort_order' => 4,
            ],
        ];

        $now = now();
        $featureIdMap = [];
        foreach ($defaultFeatures as $item) {
            $uuid = (string) Str::uuid();
            $id = DB::table('features')->insertGetId([
                'uuid' => $uuid,
                'name' => $item['name'],
                'code' => $item['code'],
                'description' => $item['description'],
                'is_active' => true,
                'sort_order' => $item['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $featureIdMap[$item['code']] = $id;
        }

        // Migrate existing plan JSON features into pivot table
        $plans = DB::table('plans')->get();
        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '{}', true) ?: [];
            foreach ($featureIdMap as $code => $featureId) {
                $isIncluded = ! empty($features[$code]);
                DB::table('plan_has_features')->insert([
                    'plan_id' => $plan->id,
                    'feature_id' => $featureId,
                    'is_included' => $isIncluded,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_has_features');
        Schema::dropIfExists('features');
    }
};
