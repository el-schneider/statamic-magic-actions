<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks background jobs with context information for recovery after navigation.
 *
 * Jobs are stored with their context (entry/asset ID, field handle) so they can be
 * recovered when the user returns to the page, even after navigating away.
 */
final class JobTracker
{
    private const CACHE_PREFIX = 'magic_actions_job_';

    private const INDEX_PREFIX = 'magic_actions_jobs_';

    private const JOB_TTL = 3600; // 1 hour

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

        // Add to context index for quick lookup
        $this->addToContextIndex($contextType, $contextId, $jobId);
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

    /**
     * Get all jobs for a specific context (entry or asset).
     */
    public function getJobsForContext(string $contextType, string $contextId): Collection
    {
        $index = $this->getContextIndex($contextType, $contextId);

        return collect($index)
            ->map(fn (string $jobId) => [
                'job_id' => $jobId,
                ...(Cache::get(self::CACHE_PREFIX.$jobId) ?? []),
            ])
            ->filter(fn (array $job) => isset($job['status']))
            ->values();
    }

    /**
     * Get pending or completed jobs for a context that haven't been acknowledged.
     */
    public function getRecoverableJobs(string $contextType, string $contextId): Collection
    {
        return $this->getJobsForContext($contextType, $contextId)
            ->filter(fn (array $job) => in_array($job['status'], ['queued', 'processing', 'completed']))
            ->values();
    }

    /**
     * Mark a job as acknowledged (user has seen/applied the result).
     */
    public function acknowledgeJob(string $jobId): void
    {
        $job = Cache::get(self::CACHE_PREFIX.$jobId);

        if (! $job) {
            return;
        }

        $job['acknowledged'] = true;
        $job['acknowledged_at'] = now()->toIso8601String();

        Cache::put(self::CACHE_PREFIX.$jobId, $job, self::JOB_TTL);

        // Remove from context index
        if (isset($job['context'])) {
            $this->removeFromContextIndex(
                $job['context']['type'],
                $job['context']['id'],
                $jobId
            );
        }
    }

    /**
     * Remove a job entirely.
     */
    public function removeJob(string $jobId): void
    {
        $job = Cache::get(self::CACHE_PREFIX.$jobId);

        if ($job && isset($job['context'])) {
            $this->removeFromContextIndex(
                $job['context']['type'],
                $job['context']['id'],
                $jobId
            );
        }

        Cache::forget(self::CACHE_PREFIX.$jobId);
    }

    /**
     * Clean up old/expired jobs from the context index.
     */
    public function cleanupContext(string $contextType, string $contextId): void
    {
        $index = $this->getContextIndex($contextType, $contextId);
        $validJobs = [];

        foreach ($index as $jobId) {
            if (Cache::has(self::CACHE_PREFIX.$jobId)) {
                $validJobs[] = $jobId;
            }
        }

        $this->setContextIndex($contextType, $contextId, $validJobs);
    }

    private function getContextIndexKey(string $contextType, string $contextId): string
    {
        return self::INDEX_PREFIX.$contextType.'_'.str_replace(['/', '::'], '_', $contextId);
    }

    private function getContextIndex(string $contextType, string $contextId): array
    {
        return Cache::get($this->getContextIndexKey($contextType, $contextId), []);
    }

    private function setContextIndex(string $contextType, string $contextId, array $jobs): void
    {
        Cache::put($this->getContextIndexKey($contextType, $contextId), $jobs, self::JOB_TTL);
    }

    private function addToContextIndex(string $contextType, string $contextId, string $jobId): void
    {
        $index = $this->getContextIndex($contextType, $contextId);

        if (! in_array($jobId, $index)) {
            $index[] = $jobId;
            $this->setContextIndex($contextType, $contextId, $index);
        }
    }

    private function removeFromContextIndex(string $contextType, string $contextId, string $jobId): void
    {
        $index = $this->getContextIndex($contextType, $contextId);
        $index = array_values(array_filter($index, fn (string $id) => $id !== $jobId));
        $this->setContextIndex($contextType, $contextId, $index);
    }
}
