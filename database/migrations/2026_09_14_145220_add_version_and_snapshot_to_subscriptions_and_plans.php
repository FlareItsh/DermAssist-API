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
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('is_active');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('plan_version')->default(1)->after('plan_id');
            $table->json('plan_snapshot')->nullable()->after('plan_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['plan_version', 'plan_snapshot']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
