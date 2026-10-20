<?php

namespace App\Service;

use App\Http\Resources\AppealResource;
use App\Models\CandidateDisease;
use App\Models\Diagnosis;
use App\Models\User;
use App\Repository\AppealRepository;

class AppealService
{
    public function __construct(
        private AppealRepository $appealRepository,
        private ModelTrainingService $modelTrainingService
    ) {}

    public function listAppeal(int $perPage = 15)
    {
        $collection = $this->appealRepository->paginate($perPage);

        return AppealResource::collection($collection);
    }

    public function listAppeals(?string $status = null)
    {
        $appeals = $this->appealRepository->getWithRelations($status);

        return AppealResource::collection($appeals);
    }

    public function listPendingAppeals()
    {
        $appeals = $this->appealRepository->getPendingWithUser();

        return AppealResource::collection($appeals);
    }

    /**
     * Submit an appeal by a doctor.
     * Immediately applies the doctor's verified diagnosis to the clinical record.
     */
    public function submitAppeal(array $payload)
    {
        $user = User::where('uuid', $payload['user_uuid'])->firstOrFail();

        $diagnosis = null;
        if (! empty($payload['diagnosis_uuid'])) {
            $diagnosis = Diagnosis::where('uuid', $payload['diagnosis_uuid'])->first();
        }

        if (! $diagnosis) {
            $diagnosis = Diagnosis::where(function ($q) use ($user) {
                $q->where('doctor_uuid', $user->uuid)
                    ->orWhere('user_uuid', $user->uuid);
            })
                ->where('label', $payload['diagnosis_label'])
                ->latest()
                ->first();
        }

        // 1. Immediately apply the doctor's clinical assessment to the diagnosis record
        if ($diagnosis) {
            $suggested = trim($payload['suggested_label']);
            $suggestedSlug = strtolower(str_replace([' ', '-'], '_', $suggested));
            $standardCategories = ['acne', 'eczema', 'herpes'];
            $isStandard = in_array($suggestedSlug, $standardCategories);

            $candidate = CandidateDisease::where('slug', $suggestedSlug)
                ->orWhere('name', $suggested)
                ->first();

            $isOutOfScope = ! $isStandard && ! in_array(strtolower($suggested), ['clear', 'healthy', 'none']);

            $diagnosis->update([
                'label' => $suggested,
                'is_out_of_scope' => $isOutOfScope,
                'out_of_scope_category' => $isOutOfScope ? ($candidate?->slug ?? $suggestedSlug) : null,
            ]);

            if ($diagnosis->clinicalNote) {
                $diagnosis->clinicalNote->update([
                    'final_diagnosis' => $suggested,
                ]);
            }
        }

        // 2. Persist appeal for AI quality assurance and dataset retraining review
        $appeal = $this->appealRepository->create([
            'user_id' => $user->id,
            'diagnosis_id' => $diagnosis?->id,
            'diagnosis_label' => $payload['diagnosis_label'],
            'suggested_label' => $payload['suggested_label'],
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
        ]);

        return new AppealResource($appeal->load(['user', 'diagnosis.patient']));
    }

    /**
     * Resolve an appeal: confirms AI misclassification and moves the image into the retraining dataset.
     */
    public function resolveAppeal(string $uuid, array $options = [])
    {
        $appeal = $this->appealRepository->findByUuid($uuid);

        $appeal->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $options['resolution_notes'] ?? null,
        ]);

        $updateDataset = $options['update_dataset'] ?? true;
        if ($updateDataset && $appeal->diagnosis) {
            $this->modelTrainingService->relocateSingleDiagnosisImage(
                $appeal->diagnosis,
                $appeal->suggested_label
            );
        }

        return new AppealResource($appeal->fresh()->load(['user', 'diagnosis.patient']));
    }

    /**
     * Restore an appeal back to pending.
     */
    public function restoreAppeal(string $uuid)
    {
        $appeal = $this->appealRepository->findByUuid($uuid);

        $appeal->update([
            'status' => 'pending',
            'resolved_at' => null,
        ]);

        return new AppealResource($appeal->fresh()->load(['user', 'diagnosis.patient']));
    }

    public function getAppeal(string $uuid)
    {
        $model = $this->appealRepository->findByUuid($uuid);

        return new AppealResource($model->load(['user', 'diagnosis.patient']));
    }

    public function getAppealByField(string $field, $value)
    {
        $model = $this->appealRepository->findByField($field, $value);

        return new AppealResource($model->load(['user', 'diagnosis.patient']));
    }

    public function updateAppeal(string $uuid, array $payload)
    {
        $model = $this->appealRepository->update($uuid, $payload);

        return new AppealResource($model->load(['user', 'diagnosis.patient']));
    }

    public function deleteAppeal(string $uuid)
    {
        $this->appealRepository->delete($uuid);

        return true;
    }
}
