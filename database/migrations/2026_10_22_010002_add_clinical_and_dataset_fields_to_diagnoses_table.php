<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('diagnoses', function (Blueprint $table) {
            $table->string('label')->nullable()->change();
            $table->decimal('confidence', 5, 4)->nullable()->change();
            $table->json('probabilities')->nullable()->change();

            $table->string('original_ai_label')->nullable()->after('label');
            $table->enum('scan_type', ['in_clinic', 'home', 'unknown'])->default('unknown')->after('original_ai_label');
            $table->boolean('is_technical_failure')->default(false)->after('scan_type');
            $table->text('technical_failure_reason')->nullable()->after('is_technical_failure');

            $table->boolean('doctor_authorized_dataset')->default(false)->after('patient_consented_dataset');
            $table->timestamp('doctor_authorized_at')->nullable()->after('doctor_authorized_dataset');
            $table->boolean('admin_approved_dataset')->default(false)->after('doctor_authorized_at');
            $table->timestamp('admin_approved_at')->nullable()->after('admin_approved_dataset');
            $table->foreignId('admin_approved_by')->nullable()->constrained('users')->nullOnDelete()->after('admin_approved_at');
        });

        // Safe Historical Backfills
        // 1. Backfill original_ai_label from the EARLIEST appeal record per diagnosis
        $earliestAppeals = DB::table('appeals')
            ->select('diagnosis_id', 'diagnosis_label')
            ->whereNotNull('diagnosis_id')
            ->whereNotNull('diagnosis_label')
            ->orderBy('created_at', 'asc')
            ->get();

        $processedDiagnosisIds = [];
        foreach ($earliestAppeals as $appeal) {
            if (! isset($processedDiagnosisIds[$appeal->diagnosis_id])) {
                DB::table('diagnoses')
                    ->where('id', $appeal->diagnosis_id)
                    ->whereNull('original_ai_label')
                    ->update(['original_ai_label' => $appeal->diagnosis_label]);
                $processedDiagnosisIds[$appeal->diagnosis_id] = true;
            }
        }

        // 2. Backfill scan_type = 'home' where proven by appointment source or draft status
        DB::table('diagnoses')
            ->join('appointments', 'diagnoses.id', '=', 'appointments.diagnosis_id')
            ->where('appointments.appointment_source', 'scan')
            ->update(['diagnoses.scan_type' => 'home']);

        DB::table('diagnoses')
            ->where('status', 'draft')
            ->where('scan_type', 'unknown')
            ->update(['scan_type' => 'home']);

        // 3. Backfill scan_type = 'in_clinic' where doctor scan is proven
        DB::table('diagnoses')
            ->whereNotNull('doctor_uuid')
            ->where('status', 'completed')
            ->where('scan_type', 'unknown')
            ->update(['scan_type' => 'in_clinic']);

        // 4. Preserve authorization state on historical contributed scans
        DB::table('diagnoses')
            ->where('contributed_to_dataset', true)
            ->update([
                'doctor_authorized_dataset' => true,
                'doctor_authorized_at' => DB::raw('contributed_at'),
                'admin_approved_dataset' => true,
                'admin_approved_at' => DB::raw('contributed_at'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diagnoses', function (Blueprint $table) {
            $table->dropForeign(['admin_approved_by']);
            $table->dropColumn([
                'original_ai_label',
                'scan_type',
                'is_technical_failure',
                'technical_failure_reason',
                'doctor_authorized_dataset',
                'doctor_authorized_at',
                'admin_approved_dataset',
                'admin_approved_at',
                'admin_approved_by',
            ]);
        });
    }
};
