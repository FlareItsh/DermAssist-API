<?php

namespace App\Service;

use App\Events\NotificationCountUpdated;
use App\Events\ScanAppealUpdated;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\CandidateDisease;
use App\Models\Diagnosis;
use App\Models\User;
use App\Repository\AppealRepository;
use Illuminate\Support\Facades\Cache;

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

    public function listAppeals(?string $status = null, ?string $diagnosisUuid = null, ?string $userUuid = null)
    {
        $appeals = $this->appealRepository->getWithRelations($status, $diagnosisUuid, $userUuid);

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

        if ($diagnosis && $diagnosis->is_technical_failure) {
            abort(422, 'Technical failure scans cannot be appealed as AI predictions. Retake the scan instead.');
        }

        $idempotencyKey = 'appeal_submit:'.$user->uuid.':'.($diagnosis ? $diagnosis->uuid : md5($payload['suggested_label']));
        if (! Cache::add($idempotencyKey, true, 5)) {
            $existing = Appeal::where('user_id', $user->id)
                ->where('diagnosis_id', $diagnosis?->id)
                ->latest()
                ->first();
            if ($existing) {
                return new AppealResource($existing->load(['user', 'diagnosis.patient', 'parentAppeal', 'reviewer']));
            }
        }

        $suggested = trim($payload['suggested_label']);

        // 1. Immediately apply the doctor's clinical assessment to the diagnosis record
        if ($diagnosis) {
            $suggestedSlug = strtolower(str_replace([' ', '-'], '_', $suggested));
            $standardCategories = ['acne', 'eczema', 'herpes'];
            $isStandard = in_array($suggestedSlug, $standardCategories);

            $candidate = CandidateDisease::where('slug', $suggestedSlug)
                ->orWhere('name', $suggested)
                ->first();

            $isOutOfScope = ! $isStandard && ! in_array(strtolower($suggested), ['clear', 'healthy', 'none']);

            $originalAi = $diagnosis->original_ai_label;
            if (empty($originalAi)) {
                $originalAi = $diagnosis->getRawOriginal('label') ?? $payload['diagnosis_label'];
            }

            $diagnosis->update([
                'label' => $suggested,
                'original_ai_label' => $originalAi,
                'is_out_of_scope' => $isOutOfScope,
                'out_of_scope_category' => $isOutOfScope ? ($candidate?->slug ?? $suggestedSlug) : null,
                'doctor_authorized_dataset' => true,
                'doctor_authorized_at' => now(),
            ]);

            if ($diagnosis->clinicalNote) {
                $diagnosis->clinicalNote->update([
                    'final_diagnosis' => $suggested,
                ]);
            }
        }

        // 2. Resolve parent appeal and resubmission count
        $parentAppeal = null;
        if (! empty($payload['parent_appeal_uuid'])) {
            $parentAppeal = Appeal::where('uuid', $payload['parent_appeal_uuid'])->first();
        } elseif ($diagnosis) {
            $parentAppeal = Appeal::where('diagnosis_id', $diagnosis->id)
                ->where('status', 'rejected')
                ->latest()
                ->first();
        }

        $parentAppealId = $parentAppeal?->id;
        $resubmissionCount = $parentAppeal ? (($parentAppeal->resubmission_count ?? 0) + 1) : 0;

        // 3. Persist appeal for AI quality assurance and dataset retraining review
        $appeal = $this->appealRepository->create([
            'user_id' => $user->id,
            'diagnosis_id' => $diagnosis?->id,
            'parent_appeal_id' => $parentAppealId,
            'resubmission_count' => $resubmissionCount,
            'diagnosis_label' => $payload['diagnosis_label'],
            'suggested_label' => $suggested,
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
        ]);

        $loaded = $appeal->load(['user', 'diagnosis.patient', 'parentAppeal', 'reviewer']);

        broadcast(new ScanAppealUpdated($loaded, 'submitted', $user->uuid));

        if ($diagnosis && $diagnosis->patient_uuid) {
            broadcast(new NotificationCountUpdated($diagnosis->patient_uuid, [
                'type' => 'clinical_diagnosis_amended',
                'diagnosis_uuid' => $diagnosis->uuid,
                'suggested_label' => $suggested,
            ]));
        }

        return new AppealResource($loaded);
    }

    /**
     * Resolve an appeal: confirms AI misclassification and moves the image into the retraining dataset.
     */
    public function resolveAppeal(string $uuid, array $options = [], ?User $reviewer = null)
    {
        $appeal = $this->appealRepository->findByUuid($uuid);

        $reviewerId = $reviewer?->id ?? (auth()->check() ? auth()->id() : null);

        $appeal->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'reviewer_id' => $reviewerId,
            'resolution_notes' => $options['resolution_notes'] ?? null,
        ]);

        if ($appeal->diagnosis) {
            $appeal->diagnosis->update([
                'doctor_authorized_dataset' => true,
                'admin_approved_dataset' => true,
                'admin_approved_at' => now(),
                'admin_approved_by' => $reviewerId,
            ]);
        }

        $updateDataset = $options['update_dataset'] ?? true;
        if ($updateDataset && $appeal->diagnosis) {
            $this->modelTrainingService->relocateSingleDiagnosisImage(
                $appeal->diagnosis->fresh(),
                $appeal->suggested_label
            );
        }

        $fresh = $appeal->fresh()->load(['user', 'diagnosis.patient', 'parentAppeal', 'reviewer']);

        broadcast(new ScanAppealUpdated($fresh, 'resolved', $fresh->user?->uuid));

        if ($fresh->user?->uuid) {
            broadcast(new NotificationCountUpdated($fresh->user->uuid, [
                'type' => 'appeal_resolved',
                'appeal_uuid' => $fresh->uuid,
            ]));
        }

        return new AppealResource($fresh);
    }

    /**
     * Reject an appeal: marks as rejected with moderation notes without retraining ingestion.
     * Note: Doctor clinical assessment is NOT reverted; clinical judgment stands.
     */
    public function rejectAppeal(string $uuid, array $options = [], ?User $reviewer = null)
    {
        $appeal = $this->appealRepository->findByUuid($uuid);

        $reviewerId = $reviewer?->id ?? (auth()->check() ? auth()->id() : null);

        $appeal->update([
            'status' => 'rejected',
            'resolved_at' => now(),
            'reviewer_id' => $reviewerId,
            'resolution_notes' => $options['resolution_notes'] ?? null,
        ]);

        $fresh = $appeal->fresh()->load(['user', 'diagnosis.patient', 'parentAppeal', 'reviewer']);

        broadcast(new ScanAppealUpdated($fresh, 'rejected', $fresh->user?->uuid));

        if ($fresh->user?->uuid) {
            broadcast(new NotificationCountUpdated($fresh->user->uuid, [
                'type' => 'appeal_rejected',
                'appeal_uuid' => $fresh->uuid,
                'resolution_notes' => $appeal->resolution_notes,
            ]));
        }

        return new AppealResource($fresh);
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

        $fresh = $appeal->fresh()->load(['user', 'diagnosis.patient']);

        broadcast(new ScanAppealUpdated($fresh, 'restored', $fresh->user?->uuid));

        if ($fresh->user?->uuid) {
            broadcast(new NotificationCountUpdated($fresh->user->uuid, [
                'type' => 'appeal_restored',
                'appeal_uuid' => $fresh->uuid,
            ]));
        }

        return new AppealResource($fresh);
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
