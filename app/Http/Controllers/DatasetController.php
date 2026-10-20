<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Service\DatasetService;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    public function __construct(private DatasetService $datasetService) {}

    private function resolveDoctorScope(Request $request): array
    {
        $user = $request->user();
        $doctorUuid = null;
        $doctorId = null;

        if ($user) {
            $roleSlug = $user->role?->slug;
            $isDoctorRole = in_array($roleSlug, ['doctor', 'secretary']);
            $isDoctorScopeRequested = $request->query('scope') === 'doctor';

            if ($isDoctorRole || $isDoctorScopeRequested) {
                if ($roleSlug === 'secretary') {
                    $doctorId = $user->doctor_id;
                    $doctorUuid = $user->doctor_id ? User::find($user->doctor_id)?->uuid : null;
                } else {
                    $doctorUuid = $request->query('doctor_uuid', $user->uuid);
                    $doc = ($doctorUuid === $user->uuid) ? $user : User::where('uuid', $doctorUuid)->first();
                    $doctorId = $doc?->id;
                }
            }
        }

        return [$doctorUuid, $doctorId];
    }

    public function index(Request $request)
    {
        $hasPagination = $request->has('page') || $request->has('per_page');
        $perPage = $request->query('per_page') ? (int) $request->query('per_page') : ($hasPagination ? 12 : null);
        [$doctorUuid, $doctorId] = $this->resolveDoctorScope($request);

        return $this->datasetService->listDatasets(
            category: $request->query('category'),
            page: $request->query('page') ? (int) $request->query('page') : ($hasPagination ? 1 : null),
            perPage: $perPage,
            all: $request->boolean('all', ! $hasPagination),
            doctorUuid: $doctorUuid,
            doctorId: $doctorId
        );
    }

    public function store(Request $request)
    {
        [$doctorUuid] = $this->resolveDoctorScope($request);

        if ($request->hasFile('images')) {
            $request->validate([
                'images' => 'required|array',
                'images.*' => 'image',
                'category' => 'required|string',
            ]);

            return $this->datasetService->addImages(
                files: $request->file('images'),
                category: $request->input('category'),
                user: $request->user(),
                doctorUuid: $doctorUuid
            );
        }

        $request->validate([
            'image' => 'required|image',
            'category' => 'required|string',
        ]);

        return $this->datasetService->addImage(
            file: $request->file('image'),
            category: $request->input('category'),
            user: $request->user(),
            doctorUuid: $doctorUuid
        );
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        [$doctorUuid] = $this->resolveDoctorScope($request);

        return $this->datasetService->removeImage(
            url: $request->input('url'),
            user: $request->user(),
            doctorUuid: $doctorUuid
        );
    }

    public function destroyBulk(Request $request)
    {
        $request->validate([
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|string',
        ]);

        [$doctorUuid] = $this->resolveDoctorScope($request);

        return $this->datasetService->removeImages(
            urls: $request->input('urls'),
            user: $request->user(),
            doctorUuid: $doctorUuid
        );
    }

    public function saveFromDiagnosis(Request $request)
    {
        $request->validate([
            'diagnosis_uuid' => 'required|uuid',
        ]);

        return $this->datasetService->saveFromDiagnosis($request->input('diagnosis_uuid'));
    }

    public function download(Request $request)
    {
        [$doctorUuid, $doctorId] = $this->resolveDoctorScope($request);

        return $this->datasetService->downloadZip(
            category: $request->input('category'),
            doctorUuid: $doctorUuid,
            doctorId: $doctorId
        );
    }
}
