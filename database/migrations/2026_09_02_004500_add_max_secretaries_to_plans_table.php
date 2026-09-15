<?php

use App\Models\Feature;
use App\Models\Plan;
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
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->default(0.00)->change();
            }
            if (Schema::hasColumn('plans', 'interval')) {
                $table->string('interval')->nullable()->change();
            }
            if (Schema::hasColumn('plans', 'interval_count')) {
                $table->integer('interval_count')->nullable()->change();
            }
            if (Schema::hasColumn('plans', 'sort_order')) {
                $table->integer('sort_order')->default(0)->change();
            }
            if (Schema::hasColumn('plans', 'trial_period_days')) {
                $table->integer('trial_period_days')->default(0)->change();
            }
            if (Schema::hasColumn('plans', 'grace_period_days')) {
                $table->integer('grace_period_days')->default(3)->change();
            }
            if (Schema::hasColumn('plans', 'is_active')) {
                $table->boolean('is_active')->default(true)->change();
            }
            if (! Schema::hasColumn('plans', 'tier_type')) {
                $table->string('tier_type')->default('individual')->after('slug');
            }
            if (! Schema::hasColumn('plans', 'price_monthly')) {
                $table->decimal('price_monthly', 10, 2)->default(0.00)->after('tier_type');
            }
            if (! Schema::hasColumn('plans', 'price_annual')) {
                $table->decimal('price_annual', 10, 2)->default(0.00)->after('price_monthly');
            }
            if (! Schema::hasColumn('plans', 'max_doctors')) {
                $table->integer('max_doctors')->nullable()->after('price_annual');
            }
            if (! Schema::hasColumn('plans', 'max_clinics')) {
                $table->integer('max_clinics')->nullable()->after('max_doctors');
            }
            if (! Schema::hasColumn('plans', 'max_secretaries')) {
                $table->integer('max_secretaries')->nullable()->default(0)->after('max_clinics');
            }
        });

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('subscriptions', 'transaction_id')) {
                    $table->string('transaction_id')->nullable()->change();
                }
                if (! Schema::hasColumn('subscriptions', 'billing_cycle')) {
                    $table->string('billing_cycle')->default('monthly')->after('plan_id');
                }
                if (! Schema::hasColumn('subscriptions', 'status')) {
                    $table->string('status')->default('active')->after('billing_cycle');
                }
                if (! Schema::hasColumn('subscriptions', 'trial_ends_at')) {
                    $table->dateTime('trial_ends_at')->nullable()->after('ends_at');
                }
                if (! Schema::hasColumn('subscriptions', 'cancellation_reason')) {
                    $table->string('cancellation_reason')->nullable()->after('cancelled_at');
                }
            });
        }

        // 1. Seed or retrieve can_have_secretary feature
        $secretaryFeature = Feature::firstOrCreate(
            ['code' => 'can_have_secretary'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Dedicated Secretary Account Access',
                'description' => 'Enables registering and managing dedicated clinic secretary accounts.',
                'is_active' => true,
                'sort_order' => 5,
            ]
        );

        // 2. Seed or update Individual Doctor Plan (with Secretary)
        $indivSecPlan = Plan::firstOrCreate(
            ['slug' => 'individual-doctor-secretary-plan'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Individual Doctor Plan (with Secretary)',
                'tier_type' => 'individual',
                'price_monthly' => 1499.00,
                'price_annual' => 14990.00,
                'max_doctors' => 1,
                'max_clinics' => 1,
                'max_secretaries' => 1,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'sort_order' => 2,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                    'can_have_secretary' => true,
                ],
            ]
        );

        // 3. Update limits on existing plans
        Plan::where('slug', 'individual-doctor-plan')->update([
            'max_secretaries' => 0,
        ]);
        Plan::where('slug', 'multi-clinic-doctor-plan')->update([
            'max_secretaries' => 3,
        ]);
        Plan::where('slug', 'clinic-group-plan')->update([
            'max_secretaries' => 10,
        ]);

        // 4. Attach features in pivot table
        $allFeatures = Feature::all();
        $plans = Plan::all();

        foreach ($plans as $plan) {
            foreach ($allFeatures as $feature) {
                $isIncluded = false;
                if ($feature->code === 'can_have_secretary') {
                    $isIncluded = ($plan->slug !== 'individual-doctor-plan' && ($plan->max_secretaries === null || $plan->max_secretaries > 0));
                } elseif ($feature->code === 'can_execute_scan' || $feature->code === 'show_in_recommendation') {
                    $isIncluded = true;
                } elseif ($feature->code === 'export_pdf_reports' || $feature->code === 'unlimited_appointments') {
                    $isIncluded = $plan->slug !== 'individual-doctor-plan';
                }

                DB::table('plan_has_features')->updateOrInsert(
                    [
                        'plan_id' => $plan->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'is_included' => $isIncluded,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('plans', 'max_secretaries')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('max_secretaries');
            });
        }
    }
};
