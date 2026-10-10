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
        Schema::table('coupons', function (Blueprint $table) {
            $table->softDeletes();
            $table->integer('max_redemptions_per_user')->nullable()->default(1)->after('max_redemptions');
            $table->decimal('min_spend', 10, 2)->nullable()->after('value');
            $table->json('applicable_plans')->nullable()->after('min_spend');
            $table->string('customer_segment')->default('all')->after('applicable_plans');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'max_redemptions_per_user',
                'min_spend',
                'applicable_plans',
                'customer_segment',
            ]);
        });
    }
};
