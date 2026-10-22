<?php

namespace App\Support;

class RepositoryPathResolver
{
    /**
     * Resolves the canonical filesystem path to the Python Algorithms data/raw directory.
     * Returns null if running in distributed cloud environments where algorithms is hosted remotely.
     */
    public static function getAlgorithmsDataRawPath(): ?string
    {
        $configured = config('services.ai.data_path') ?: env('ALGORITHMS_DATA_PATH');
        if ($configured && is_dir($configured)) {
            return realpath($configured) ?: $configured;
        }

        // Standard sibling directory in repository layout: /DermAssist/algorithms/data/raw
        $candidateStandard = dirname(base_path()).DIRECTORY_SEPARATOR.'algorithms'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'raw';
        if (is_dir($candidateStandard)) {
            return realpath($candidateStandard) ?: $candidateStandard;
        }

        // Legacy candidate: /DermAssist/DermAssist-Algorithms/data/raw
        $candidateLegacy = dirname(base_path()).DIRECTORY_SEPARATOR.'DermAssist-Algorithms'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'raw';
        if (is_dir($candidateLegacy)) {
            return realpath($candidateLegacy) ?: $candidateLegacy;
        }

        return null;
    }

    /**
     * Resolves the canonical filesystem path to the Laravel Public Dataset storage directory.
     */
    public static function getLaravelDatasetPath(): string
    {
        return storage_path('app/public/dataset');
    }
}
