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
        Schema::create('candidate_diseases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('badge')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $initialCandidates = [
            [
                'name' => 'Psoriasis',
                'slug' => 'psoriasis',
                'category' => 'Autoimmune',
                'description' => 'Chronic autoimmune condition with scaly, erythematous plaques.',
                'badge' => 'High Priority',
                'icon' => 'lucide:flame',
                'is_default' => true,
            ],
            [
                'name' => 'Ringworm (Tinea)',
                'slug' => 'ringworm',
                'category' => 'Fungal Infection',
                'description' => 'Annular dermatophyte lesions with characteristic raised borders.',
                'badge' => 'Common',
                'icon' => 'lucide:circle-dot',
                'is_default' => true,
            ],
            [
                'name' => 'Vitiligo',
                'slug' => 'vitiligo',
                'category' => 'Pigmentary Disorder',
                'description' => 'Autoimmune destruction of melanocytes resulting in depigmented patches.',
                'badge' => 'Pigmentary',
                'icon' => 'lucide:sun',
                'is_default' => true,
            ],
            [
                'name' => 'Melanoma (Research)',
                'slug' => 'melanoma',
                'category' => 'Atypical Lesion',
                'description' => 'Asymmetric pigmented lesions requiring careful boundary analysis.',
                'badge' => 'Critical',
                'icon' => 'lucide:alert-octagon',
                'is_default' => true,
            ],
            [
                'name' => 'Hives (Urticaria)',
                'slug' => 'hives',
                'category' => 'Allergic Reaction',
                'description' => 'Transient pruritic edematous erythematous wheals and flares.',
                'badge' => 'Allergic',
                'icon' => 'lucide:wind',
                'is_default' => true,
            ],
            [
                'name' => 'Warts (HPV)',
                'slug' => 'warts',
                'category' => 'Viral Infection',
                'description' => 'Hyperkeratotic verrucous exophytic papules caused by HPV.',
                'badge' => 'Viral',
                'icon' => 'lucide:shield-alert',
                'is_default' => true,
            ],
            [
                'name' => 'Lupus Erythematosus',
                'slug' => 'lupus',
                'category' => 'Autoimmune',
                'description' => 'Cutaneous lupus lesions including discoid and malar photosensitive patterns.',
                'badge' => 'Autoimmune',
                'icon' => 'lucide:dna',
                'is_default' => true,
            ],
            [
                'name' => 'Rosacea',
                'slug' => 'rosacea',
                'category' => 'Vascular Condition',
                'description' => 'Centrofacial erythema, telangiectasia, and inflammatory papulopustules.',
                'badge' => 'Vascular',
                'icon' => 'lucide:sparkles',
                'is_default' => true,
            ],
            [
                'name' => 'Alopecia Areata',
                'slug' => 'alopecia_areata',
                'category' => 'Autoimmune',
                'description' => 'Autoimmune follicular condition causing round or oval patches of sudden hair loss.',
                'badge' => 'Autoimmune',
                'icon' => 'lucide:sparkle',
                'is_default' => false,
            ],
            [
                'name' => 'Impetigo',
                'slug' => 'impetigo',
                'category' => 'Bacterial Infection',
                'description' => 'Contagious superficial bacterial skin infection with honey-colored crusted erosions.',
                'badge' => 'Bacterial',
                'icon' => 'lucide:shield-alert',
                'is_default' => false,
            ],
            [
                'name' => 'Scabies',
                'slug' => 'scabies',
                'category' => 'Parasitic Infestation',
                'description' => 'Intensely pruritic contagious parasitic skin infestation characterized by burrows.',
                'badge' => 'Parasitic',
                'icon' => 'lucide:bug',
                'is_default' => false,
            ],
            [
                'name' => 'Seborrheic Dermatitis',
                'slug' => 'seborrheic_dermatitis',
                'category' => 'Inflammatory Condition',
                'description' => 'Chronic papulosquamous disorder affecting sebum-rich areas with greasy yellowish scales.',
                'badge' => 'Scaly',
                'icon' => 'lucide:sparkles',
                'is_default' => false,
            ],
            [
                'name' => 'Melasma',
                'slug' => 'melasma',
                'category' => 'Pigmentary Disorder',
                'description' => 'Acquired hypermelanosis characterized by irregular brown macules in sun-exposed facial zones.',
                'badge' => 'Pigmentary',
                'icon' => 'lucide:sun',
                'is_default' => false,
            ],
            [
                'name' => 'Keratosis Pilaris',
                'slug' => 'keratosis_pilaris',
                'category' => 'Follicular Condition',
                'description' => 'Follicular keratotic papules on proximal extensor surfaces of upper arms and thighs.',
                'badge' => 'Follicular',
                'icon' => 'lucide:layers',
                'is_default' => false,
            ],
        ];

        $now = now();
        foreach ($initialCandidates as $candidate) {
            DB::table('candidate_diseases')->insert(array_merge($candidate, [
                'uuid' => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_diseases');
    }
};
