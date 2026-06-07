<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('diagnoses', function (Blueprint $table) {
            $table->string('patient_uuid')->nullable()->after('user_uuid');
            $table->string('doctor_uuid')->nullable()->after('patient_uuid');
        });

        // Data migration: map user_uuid to patient_uuid
        \Illuminate\Support\Facades\DB::table('diagnoses')->update([
            'patient_uuid' => \Illuminate\Support\Facades\DB::raw('user_uuid')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diagnoses', function (Blueprint $table) {
            $table->dropColumn(['patient_uuid', 'doctor_uuid']);
        });
    }
};
