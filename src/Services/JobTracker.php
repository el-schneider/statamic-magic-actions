<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks background jobs with context information for recovery after navigation.
 *
 * Jobs are stored with their context (entry/asset ID, field handle) so they can be
 * recovered when the user returns to the page, even after navigating away.
 */
final class JobTracker
{
    public const CACHE_PREFIX = 'magic_actions_job_';

    public const JOB_TTL = 3600; // 1 hour

    /**
     * Create a new job with context information.
     */
    public function createJob(
        string $jobId,
        string $action,
        string $contextType,
        string $contextId,
        string $fieldHandle
    ): void {
        $jobData = [
            'status' => 'queued',
            'message' => 'Job has been queued',
            'context' => [
                'type' => $contextType,
                'id' => $contextId,
                'field' => $fieldHandle,
                'action' => $action,
            ],
            'created_at' => now()->toIso8601String(),
        ];

        // Store the job data
        Cache::put(self::CACHE_PREFIX.$jobId, $jobData, self::JOB_TTL);
    }

    /**
     * Update job status.
     */
    public function updateStatus(string $jobId, string $status, ?string $message = null, mixed $data = null): void
    {
        $job = Cache::get(self::CACHE_PREFIX.$jobId);

        if (! $job) {
            return;
        }

        $job['status'] = $status;

        if ($message !== null) {
            $job['message'] = $message;
        }

        if ($data !== null) {
            $job['data'] = $data;
        }

        $job['updated_at'] = now()->toIso8601String();

        Cache::put(self::CACHE_PREFIX.$jobId, $job, self::JOB_TTL);
    }

    /**
     * Get a specific job by ID.
     */
    public function getJob(string $jobId): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$jobId);
    }
}
