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
        if (! Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable()->after('location');
            });
        }

        // Backfill approximate date of birth for existing records with age
        DB::table('users')
            ->whereNotNull('age')
            ->whereNull('date_of_birth')
            ->orderBy('id')
            ->each(function ($u) {
                if (is_numeric($u->age) && (int) $u->age > 0) {
                    DB::table('users')->where('id', $u->id)->update([
                        'date_of_birth' => now()->subYears((int) $u->age)->startOfYear()->toDateString(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('date_of_birth');
        });
    }
};
