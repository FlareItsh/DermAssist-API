<?php

namespace App\Service;

use App\Models\Diagnosis;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ClinicalReportPdfService
{
    /**
     * Generate downloadable PDF clinical assessment report.
     */
    public function generatePdf(string $uuid, User $user): Response
    {
        $query = Diagnosis::with(['patient', 'doctor', 'clinicalNote.doctor', 'user']);
        $diagnosis = is_numeric($uuid)
            ? $query->where('id', $uuid)->firstOrFail()
            : $query->where('uuid', $uuid)->firstOrFail();

        // 1. Authorization check
        $this->authorizeExport($diagnosis, $user);

        // 2. Resolve Doctor
        $doctor = $diagnosis->doctor ?? ($diagnosis->clinicalNote ? $diagnosis->clinicalNote->doctor : null);

        // 3. Resolve Patient
        $patient = $diagnosis->patient ?? $diagnosis->user;
        $patientAge = null;
        if ($patient) {
            $ageVal = $patient->age ?? ($patient->date_of_birth ? Carbon::parse($patient->date_of_birth)->age : null);
            if ($ageVal !== null) {
                $patientAge = $ageVal.' yrs';
            }
        }

        // 4. Lesion Image Base64 Encoding for DomPDF
        $imageBase64 = null;
        if ($diagnosis->image_path) {
            $imagePath = $diagnosis->image_path;
            $disk = Storage::disk('public')->exists($imagePath) ? Storage::disk('public') : Storage::disk('local');
            if ($disk->exists($imagePath)) {
                $fileBytes = $disk->get($imagePath);
                $mime = 'image/jpeg';
                if (str_ends_with(strtolower($imagePath), '.png')) {
                    $mime = 'image/png';
                } elseif (str_ends_with(strtolower($imagePath), '.webp')) {
                    $mime = 'image/webp';
                }
                $imageBase64 = 'data:'.$mime.';base64,'.base64_encode($fileBytes);
            }
        }

        // 5. Probability distribution formatting
        $probabilities = [];
        if (! empty($diagnosis->probabilities) && is_array($diagnosis->probabilities)) {
            foreach ($diagnosis->probabilities as $lbl => $prob) {
                $val = round(((float) $prob) * 100);
                if ($val > 0) {
                    $probabilities[] = [
                        'label' => (string) $lbl,
                        'value' => $val,
                    ];
                }
            }
            usort($probabilities, fn ($a, $b) => $b['value'] <=> $a['value']);
        }

        // 6. Clinical note details
        $note = $diagnosis->clinicalNote;

        $viewData = [
            'recordId' => $diagnosis->uuid,
            'dateFormatted' => $diagnosis->created_at ? $diagnosis->created_at->format('F d, Y h:i A') : Carbon::now()->format('F d, Y'),
            'doctorName' => $doctor ? trim("{$doctor->first_name} {$doctor->last_name}") : null,
            'doctorAffiliation' => $doctor?->affiliation ?? 'DermAssist Clinical Network',
            'patientName' => $patient ? trim("{$patient->first_name} {$patient->last_name}") : 'Walk-In Patient',
            'patientAge' => $patientAge,
            'patientSex' => $patient?->gender ?? $patient?->sex ?? null,
            'patientContact' => $patient?->phone ?? $patient?->email ?? null,
            'patientUuid' => $patient?->uuid ?? $diagnosis->uuid,
            'imageBase64' => $imageBase64,
            'primaryCondition' => $diagnosis->label ?: 'Unspecified Lesion Examination',
            'confidencePercent' => $diagnosis->confidence ? round($diagnosis->confidence * 100) : 0,
            'probabilities' => $probabilities,
            'finalDiagnosis' => $note?->final_diagnosis,
            'historyOfPresentIllness' => $note?->history_of_present_illness,
            'physicalExam' => $note?->physical_exam,
            'prescription' => $note?->prescription,
            'patientEducation' => $note?->patient_education,
            'followUpDate' => $note?->follow_up_date ? Carbon::parse($note->follow_up_date)->format('F d, Y') : null,
            'followUpInstructions' => $note?->follow_up_instructions,
        ];

        $pdf = Pdf::loadView('pdf.clinical-report', $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'Helvetica',
            ]);

        $fileName = 'clinical-assessment-'.substr($diagnosis->uuid, 0, 8).'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }

    /**
     * Authorize user access to clinical report PDF.
     */
    private function authorizeExport(Diagnosis $diagnosis, User $user): void
    {
        $role = $user->role?->slug;

        // Admin always authorized
        if ($role === 'admin') {
            return;
        }

        // Doctor gating: Must have active plan with export_pdf_reports flag
        if ($role === 'doctor') {
            if (! $user->canAccessFeature('export_pdf_reports')) {
                abort(response()->json([
                    'status' => 'error',
                    'code' => 'PLAN_FEATURE_RESTRICTED',
                    'message' => 'Your subscription plan does not include PDF Clinical Report Exports. Upgrade to a plan that includes PDF clinical exports.',
                ], 403));
            }

            return;
        }

        // Secretary delegation
        if ($role === 'secretary' && $user->doctor_id) {
            $doctor = $user->doctor;
            if ($doctor && ! $doctor->canAccessFeature('export_pdf_reports')) {
                abort(response()->json([
                    'status' => 'error',
                    'code' => 'PLAN_FEATURE_RESTRICTED',
                    'message' => 'Attending doctor subscription does not include PDF Clinical Report Exports.',
                ], 403));
            }

            return;
        }

        // Patient access: Only own record
        if ($role === 'patient') {
            $isOwner = ($diagnosis->patient_uuid === $user->uuid) || ($diagnosis->user_uuid === $user->uuid);
            if (! $isOwner) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to clinical report.',
                ], 403));
            }

            return;
        }

        abort(403, 'Unauthorized access.');
    }
}
