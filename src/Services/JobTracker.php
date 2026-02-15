<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Tracks background jobs with context information for recovery after navigation.
 *
 * Jobs are stored with their context (entry/asset ID, field handle) so they can be
 * recovered when the user returns to the page, even after navigating away.
 */
final class JobTracker
{
    public const CACHE_PREFIX = 'magic_actions_job_';

    public const BATCH_CACHE_PREFIX = 'magic_actions_batch_';

    public const BATCH_JOBS_CACHE_PREFIX = 'magic_actions_batch_jobs_';

    public const CACHE_TAG = 'magic_actions';

    public const JOB_TTL = 86400; // 24 hours (default fallback).

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
        $this->cachePut(self::CACHE_PREFIX.$jobId, $jobData);
    }

    /**
     * Update job status.
     */
    public function updateStatus(string $jobId, string $status, ?string $message = null, mixed $data = null): void
    {
        $job = $this->cacheGet(self::CACHE_PREFIX.$jobId);

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

        $this->cachePut(self::CACHE_PREFIX.$jobId, $job);
    }

    /**
     * Get a specific job by ID.
     */
    public function getJob(string $jobId): ?array
    {
        $job = $this->cacheGet(self::CACHE_PREFIX.$jobId);

        return is_array($job) ? $job : null;
    }

    /**
     * Create a new batch and return its ID.
     */
    public function createBatch(string $action, int $totalItems, array $metadata = []): string
    {
        $batchId = (string) Str::uuid();
        $batchData = [
            'batch_id' => $batchId,
            'action' => $action,
            'total' => max(0, $totalItems),
            'created_at' => now()->toIso8601String(),
            'metadata' => $metadata,
        ];

        $this->cachePut($this->batchKey($batchId), $batchData);
        $this->cachePut($this->batchJobsKey($batchId), []);

        return $batchId;
    }

    /**
     * Associate a job with an existing batch.
     */
    public function addJobToBatch(string $batchId, string $jobId): void
    {
        $batch = $this->cacheGet($this->batchKey($batchId));

        if (! is_array($batch)) {
            return;
        }

        $jobIds = $this->getBatchJobs($batchId);

        if (! in_array($jobId, $jobIds, true)) {
            $jobIds[] = $jobId;
        }

        $batch['updated_at'] = now()->toIso8601String();

        $this->cachePut($this->batchKey($batchId), $batch);
        $this->cachePut($this->batchJobsKey($batchId), array_values($jobIds));
    }

    /**
     * Get aggregate batch status and metadata.
     */
    public function getBatch(string $batchId): ?array
    {
        $batch = $this->cacheGet($this->batchKey($batchId));

        if (! is_array($batch)) {
            return null;
        }

        $jobIds = $this->getBatchJobs($batchId);
        $completed = 0;
        $failed = 0;
        $hasProcessing = false;
        $errors = [];

        foreach ($jobIds as $jobId) {
            $job = $this->getJob($jobId);
            $status = (string) ($job['status'] ?? 'queued');

            if ($status === 'completed') {
                $completed++;

                continue;
            }

            if ($status === 'failed') {
                $failed++;
                $errors[] = [
                    'job_id' => $jobId,
                    'message' => (string) ($job['message'] ?? 'Job failed'),
                ];

                continue;
            }

            if ($status === 'processing') {
                $hasProcessing = true;
            }
        }

        $total = max(0, (int) ($batch['total'] ?? count($jobIds)));
        $pending = max($total - $completed - $failed, 0);

        return [
            'batch_id' => $batchId,
            'action' => (string) ($batch['action'] ?? ''),
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'status' => $this->resolveBatchStatus($total, $completed, $failed, $pending, $hasProcessing),
            'errors' => $errors,
            'created_at' => $batch['created_at'] ?? null,
            'metadata' => is_array($batch['metadata'] ?? null) ? $batch['metadata'] : [],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getBatchJobs(string $batchId): array
    {
        $jobIds = $this->cacheGet($this->batchJobsKey($batchId));

        if (! is_array($jobIds)) {
            return [];
        }

        $normalized = [];

        foreach ($jobIds as $jobId) {
            if (! is_string($jobId) || $jobId === '') {
                continue;
            }

            $normalized[$jobId] = $jobId;
        }

        return array_values($normalized);
    }

    private function resolveBatchStatus(
        int $total,
        int $completed,
        int $failed,
        int $pending,
        bool $hasProcessing
    ): string {
        if ($total === 0) {
            return 'pending';
        }

        if ($pending === 0) {
            if ($failed === 0) {
                return 'completed';
            }

            if ($completed === 0) {
                return 'failed';
            }

            return 'partial_failure';
        }

        if ($hasProcessing || $completed > 0 || $failed > 0) {
            return 'processing';
        }

        return 'pending';
    }

    private function batchKey(string $batchId): string
    {
        return self::BATCH_CACHE_PREFIX.$batchId;
    }

    private function batchJobsKey(string $batchId): string
    {
        return self::BATCH_JOBS_CACHE_PREFIX.$batchId;
    }

    private function cacheGet(string $key): mixed
    {
        if ($this->supportsCacheTags()) {
            return Cache::tags([self::CACHE_TAG])->get($key);
        }

        return Cache::get($key);
    }

    private function cachePut(string $key, mixed $value): void
    {
        $ttl = $this->cacheTtl();

        if ($this->supportsCacheTags()) {
            Cache::tags([self::CACHE_TAG])->put($key, $value, $ttl);

            return;
        }

        Cache::put($key, $value, $ttl);
    }

    private function supportsCacheTags(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    private function cacheTtl(): int
    {
        $ttl = (int) Config::get('statamic.magic-actions.batch.cache_ttl', self::JOB_TTL);

        return $ttl > 0 ? $ttl : self::JOB_TTL;
    }
}
