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
        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Populate existing records with UUIDs
        DB::table('doctor_verifications')->whereNull('uuid')->orderBy('id')->each(function ($record) {
            DB::table('doctor_verifications')
                ->where('id', $record->id)
                ->update(['uuid' => (string) Str::uuid()]);
        });

        DB::table('role_has_permissions')->whereNull('uuid')->orderBy('id')->each(function ($record) {
            DB::table('role_has_permissions')
                ->where('id', $record->id)
                ->update(['uuid' => (string) Str::uuid()]);
        });

        // Now make them unique
        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_verifications', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
