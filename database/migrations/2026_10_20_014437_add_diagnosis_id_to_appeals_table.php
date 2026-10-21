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
        Schema::table('appeals', function (Blueprint $table) {
            $table->foreignId('diagnosis_id')
                ->nullable()
                ->after('user_id')
                ->constrained('diagnoses')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('status');
            $table->text('resolution_notes')->nullable()->after('resolved_at');
        });

        // Backfill existing appeals with their matching diagnosis if available
        $appeals = DB::table('appeals')->whereNull('diagnosis_id')->get();
        foreach ($appeals as $appeal) {
            $user = DB::table('users')->where('id', $appeal->user_id)->first();
            if ($user && $user->uuid) {
                // Find diagnosis created by this doctor / user matching diagnosis label
                $matchingDiag = DB::table('diagnoses')
                    ->where(function ($q) use ($user) {
                        $q->where('doctor_uuid', $user->uuid)
                            ->orWhere('user_uuid', $user->uuid);
                    })
                    ->where('label', $appeal->diagnosis_label)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($matchingDiag) {
                    DB::table('appeals')->where('id', $appeal->id)->update([
                        'diagnosis_id' => $matchingDiag->id,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropForeign(['diagnosis_id']);
            $table->dropColumn(['diagnosis_id', 'resolved_at', 'resolution_notes']);
        });
    }
};
