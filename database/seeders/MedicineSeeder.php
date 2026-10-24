<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch Doctor Account(s)
        $doctors = User::where('email', 'doc@derm.com')->get();
        if ($doctors->isEmpty()) {
            $fallbackDoctor = User::where('email', 'ben@derm.com')->first();
            if ($fallbackDoctor) {
                $doctors = collect([$fallbackDoctor]);
            } else {
                $doctors = User::whereHas('role', fn ($q) => $q->where('slug', 'doctor'))->get();
            }
        }

        if ($doctors->isEmpty()) {
            $this->command?->warn('No doc@derm.com doctor account found to seed medicines.');

            return;
        }

        $medicines = [
            [
                'name' => 'Hydrocortisone Cream',
                'description' => 'Mild topical corticosteroid for eczema, dermatitis, and localized inflammatory skin eruptions.',
                'variants' => [
                    ['dosage' => '1', 'unit' => '%', 'default_frequency' => 'Apply BID'],
                    ['dosage' => '2.5', 'unit' => '%', 'default_frequency' => 'Apply BID'],
                ],
            ],
            [
                'name' => 'Betamethasone Dipropionate',
                'description' => 'Potent topical corticosteroid for severe eczema, psoriasis, and lichen planus.',
                'variants' => [
                    ['dosage' => '0.05', 'unit' => '%', 'default_frequency' => 'Apply once daily'],
                ],
            ],
            [
                'name' => 'Clobetasol Propionate',
                'description' => 'Super-high potency topical corticosteroid for stubborn recalcitrant dermatoses.',
                'variants' => [
                    ['dosage' => '0.05', 'unit' => '%', 'default_frequency' => 'Apply thin layer once daily for max 2 weeks'],
                ],
            ],
            [
                'name' => 'Tretinoin Cream',
                'description' => 'Topical retinoid promoting cellular turnover for acne vulgaris and photoaging.',
                'variants' => [
                    ['dosage' => '0.025', 'unit' => '%', 'default_frequency' => 'Apply once daily at bedtime'],
                    ['dosage' => '0.05', 'unit' => '%', 'default_frequency' => 'Apply once daily at bedtime'],
                    ['dosage' => '0.1', 'unit' => '%', 'default_frequency' => 'Apply once daily at bedtime'],
                ],
            ],
            [
                'name' => 'Adapalene Gel',
                'description' => 'Third-generation selective retinoid for mild-to-moderate comedonal and inflammatory acne.',
                'variants' => [
                    ['dosage' => '0.1', 'unit' => '%', 'default_frequency' => 'Apply once daily at bedtime'],
                    ['dosage' => '0.3', 'unit' => '%', 'default_frequency' => 'Apply once daily at bedtime'],
                ],
            ],
            [
                'name' => 'Benzoyl Peroxide Gel',
                'description' => 'Topical antimicrobial and comedolytic agent for inflammatory acne lesions.',
                'variants' => [
                    ['dosage' => '2.5', 'unit' => '%', 'default_frequency' => 'Apply once daily'],
                    ['dosage' => '5', 'unit' => '%', 'default_frequency' => 'Apply BID'],
                ],
            ],
            [
                'name' => 'Isotretinoin',
                'description' => 'Oral retinoid for severe nodulocystic recalcitrant acne unresponsive to conventional therapy.',
                'variants' => [
                    ['dosage' => '10', 'unit' => 'mg', 'default_frequency' => 'Once daily with high-fat meal'],
                    ['dosage' => '20', 'unit' => 'mg', 'default_frequency' => 'Once daily with high-fat meal'],
                ],
            ],
            [
                'name' => 'Mupirocin Ointment',
                'description' => 'Topical antibacterial ointment for impetigo and secondary bacterial skin infections.',
                'variants' => [
                    ['dosage' => '2', 'unit' => '%', 'default_frequency' => 'Apply TID for 7-10 days'],
                ],
            ],
            [
                'name' => 'Clindamycin Phosphate Solution',
                'description' => 'Topical lincosamide antibiotic solution for inflammatory acne vulgaris.',
                'variants' => [
                    ['dosage' => '1', 'unit' => '%', 'default_frequency' => 'Apply BID'],
                ],
            ],
            [
                'name' => 'Doxycycline Hyclate',
                'description' => 'Oral tetracycline antibiotic with anti-inflammatory activity for papulopustular rosacea and acne.',
                'variants' => [
                    ['dosage' => '50', 'unit' => 'mg', 'default_frequency' => 'Once daily with full glass of water'],
                    ['dosage' => '100', 'unit' => 'mg', 'default_frequency' => 'Once daily (OD)'],
                ],
            ],
            [
                'name' => 'Cephalexin',
                'description' => 'First-generation cephalosporin for bacterial cellulitis, folliculitis, and furunculosis.',
                'variants' => [
                    ['dosage' => '500', 'unit' => 'mg', 'default_frequency' => 'Every 6 hours'],
                ],
            ],
            [
                'name' => 'Ketoconazole Shampoo / Cream',
                'description' => 'Broad-spectrum imidazole antifungal for seborrheic dermatitis, dandruff, and tinea versicolor.',
                'variants' => [
                    ['dosage' => '2', 'unit' => '%', 'default_frequency' => 'Apply twice weekly for shampoo; Apply BID for cream'],
                ],
            ],
            [
                'name' => 'Terbinafine HCl',
                'description' => 'Allylamine antifungal for dermatophyte infections, tinea pedis, and onychomycosis.',
                'variants' => [
                    ['dosage' => '1', 'unit' => '%', 'default_frequency' => 'Apply once daily (cream)'],
                    ['dosage' => '250', 'unit' => 'mg', 'default_frequency' => 'Once daily (OD) for 6-12 weeks (tablet)'],
                ],
            ],
            [
                'name' => 'Fluconazole',
                'description' => 'Systemic triazole antifungal for recurrent cutaneous candidiasis and extensive fungal infections.',
                'variants' => [
                    ['dosage' => '150', 'unit' => 'mg', 'default_frequency' => 'Once weekly'],
                ],
            ],
            [
                'name' => 'Cetirizine HCl',
                'description' => 'Second-generation non-sedating H1-antihistamine for pruritus, allergic dermatitis, and urticaria.',
                'variants' => [
                    ['dosage' => '10', 'unit' => 'mg', 'default_frequency' => 'Once daily at bedtime'],
                ],
            ],
            [
                'name' => 'Bilastine',
                'description' => 'Non-sedating second-generation antihistamine for chronic spontaneous urticaria.',
                'variants' => [
                    ['dosage' => '20', 'unit' => 'mg', 'default_frequency' => 'Once daily on empty stomach'],
                ],
            ],
            [
                'name' => 'Tacrolimus Ointment',
                'description' => 'Topical calcineurin inhibitor for moderate-to-severe atopic dermatitis.',
                'variants' => [
                    ['dosage' => '0.03', 'unit' => '%', 'default_frequency' => 'Apply thin layer BID'],
                    ['dosage' => '0.1', 'unit' => '%', 'default_frequency' => 'Apply thin layer BID'],
                ],
            ],
        ];

        foreach ($doctors as $doctor) {
            // Delete existing medicines for doctor to ensure idempotency
            Medicine::where('doctor_id', $doctor->id)->delete();

            $createdCount = 0;
            $variantsCount = 0;

            foreach ($medicines as $item) {
                $medicine = Medicine::create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $doctor->id,
                    'name' => $item['name'],
                    'description' => $item['description'],
                ]);

                foreach ($item['variants'] as $v) {
                    $medicine->variants()->create([
                        'uuid' => (string) Str::uuid(),
                        'dosage' => $v['dosage'],
                        'unit' => $v['unit'],
                        'default_frequency' => $v['default_frequency'],
                    ]);
                    $variantsCount++;
                }

                $createdCount++;
            }

            $this->command?->info(sprintf(
                'Seeded %d medicines with %d dosage variants for doctor: %s (%s)',
                $createdCount,
                $variantsCount,
                $doctor->email,
                $doctor->first_name.' '.$doctor->last_name
            ));
        }
    }
}
