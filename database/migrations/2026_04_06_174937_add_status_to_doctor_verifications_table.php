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
        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->enum('status', ['pending', 'verified', 'declined'])->default('pending')->after('is_verified');
        });

        // Migrate existing verified data
        DB::table('doctor_verifications')
            ->where('is_verified', 1)
            ->update(['status' => 'verified']);

        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->dropColumn('is_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->tinyInteger('is_verified')->default(0)->after('prc_number');
        });

        // Roll back status to verified
        DB::table('doctor_verifications')
            ->where('status', 'verified')
            ->update(['is_verified' => 1]);

        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
