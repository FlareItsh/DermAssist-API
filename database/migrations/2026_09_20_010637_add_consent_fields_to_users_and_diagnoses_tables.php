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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('consent_dataset')->default(false)->after('is_doctor_registered');
            $table->timestamp('terms_accepted_at')->nullable()->after('consent_dataset');
        });

        Schema::table('diagnoses', function (Blueprint $table) {
            $table->boolean('patient_consented_dataset')->default(false)->after('status');
            $table->boolean('contributed_to_dataset')->default(false)->after('patient_consented_dataset');
            $table->timestamp('contributed_at')->nullable()->after('contributed_to_dataset');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['consent_dataset', 'terms_accepted_at']);
        });

        Schema::table('diagnoses', function (Blueprint $table) {
            $table->dropColumn(['patient_consented_dataset', 'contributed_to_dataset', 'contributed_at']);
        });
    }
};
