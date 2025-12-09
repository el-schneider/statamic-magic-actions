<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

use Closure;
use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ActionsController extends Controller
{
    public function __construct(
        private readonly JobTracker $jobTracker
    ) {}

    /**
     * Start a completion job
     */
    public function completion(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'text' => 'required|string',
                'action' => 'required|string',
                'context_type' => 'sometimes|string',
                'context_id' => 'sometimes|string',
                'field_handle' => 'sometimes|string',
            ]);

            $text = $request->input('text');
            $action = $request->input('action');

            if (! app(ActionLoader::class)->exists($action)) {
                return response()->json(['error' => 'Action not found'], 404);
            }

            $jobId = (string) Str::uuid();
            $context = $this->extractContext($request);

            return $this->queueBackgroundJob($jobId, $action, $context, function () use ($jobId, $action, $text, $context) {
                ProcessPromptJob::dispatch($jobId, $action, ['text' => $text], null, $context);
            });
        } catch (MissingApiKeyException) {
            return $this->apiKeyNotConfiguredError('Completion');
        } catch (InvalidArgumentException $e) {
            Log::warning('Completion request validation failed', [
                'action' => $request->input('action'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Start a vision job
     */
    public function vision(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'asset_path' => 'required|string',
                'action' => 'required|string',
                'variables' => 'sometimes|array',
                'context_type' => 'sometimes|string',
                'context_id' => 'sometimes|string',
                'field_handle' => 'sometimes|string',
            ]);

            $assetPath = $request->input('asset_path');
            $action = $request->input('action');
            $variables = $request->input('variables', []);

            if (! app(ActionLoader::class)->exists($action)) {
                return response()->json(['error' => 'Action not found'], 404);
            }

            $jobId = (string) Str::uuid();
            $context = $this->extractContext($request);

            return $this->queueBackgroundJob($jobId, $action, $context, function () use ($jobId, $action, $assetPath, $variables, $context) {
                ProcessPromptJob::dispatch($jobId, $action, $variables, $assetPath, $context);
            });
        } catch (MissingApiKeyException) {
            return $this->apiKeyNotConfiguredError('Vision');
        } catch (InvalidArgumentException $e) {
            Log::warning('Vision request validation failed', [
                'action' => $request->input('action'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Start a transcription job
     */
    public function transcribe(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'asset_path' => 'required|string',
                'action' => 'required|string',
                'context_type' => 'sometimes|string',
                'context_id' => 'sometimes|string',
                'field_handle' => 'sometimes|string',
            ]);

            $assetPath = $request->input('asset_path');
            $action = $request->input('action');

            if (! app(ActionLoader::class)->exists($action)) {
                return response()->json(['error' => 'Action not found'], 404);
            }

            $jobId = (string) Str::uuid();
            $context = $this->extractContext($request);

            return $this->queueBackgroundJob($jobId, $action, $context, function () use ($jobId, $action, $assetPath, $context) {
                ProcessPromptJob::dispatch($jobId, $action, [], $assetPath, $context);
            });
        } catch (MissingApiKeyException) {
            return $this->apiKeyNotConfiguredError('Transcription');
        }
    }

    /**
     * Check the status of a job
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $job = $this->jobTracker->getJob($jobId);

        if (! $job) {
            Log::warning('Job not found in cache', ['job_id' => $jobId]);

            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json($job);
    }

    /**
     * Get all recoverable jobs for a specific context (entry or asset).
     */
    public function jobs(Request $request, string $contextType, string $contextId): JsonResponse
    {
        // Clean up expired jobs first
        $this->jobTracker->cleanupContext($contextType, $contextId);

        $jobs = $this->jobTracker->getRecoverableJobs($contextType, $contextId);

        return response()->json([
            'jobs' => $jobs->toArray(),
        ]);
    }

    /**
     * Acknowledge a job (mark as seen/applied by user).
     */
    public function acknowledge(Request $request, string $jobId): JsonResponse
    {
        $job = $this->jobTracker->getJob($jobId);

        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $this->jobTracker->acknowledgeJob($jobId);

        Log::info('Job acknowledged', ['job_id' => $jobId]);

        return response()->json(['success' => true]);
    }

    /**
     * Dismiss a job without applying the result.
     */
    public function dismiss(Request $request, string $jobId): JsonResponse
    {
        $job = $this->jobTracker->getJob($jobId);

        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $this->jobTracker->removeJob($jobId);

        Log::info('Job dismissed', ['job_id' => $jobId]);

        return response()->json(['success' => true]);
    }

    private function apiKeyNotConfiguredError(string $action): JsonResponse
    {
        Log::warning("$action request rejected: OpenAI API key not configured");

        return response()->json([
            'error' => 'OpenAI API key is not configured',
            'message' => 'Please configure your OpenAI API key in the addon settings',
        ], 500);
    }

    /**
     * Extract context information from the request.
     */
    private function extractContext(Request $request): ?array
    {
        $contextType = $request->input('context_type');
        $contextId = $request->input('context_id');
        $fieldHandle = $request->input('field_handle');

        if ($contextType && $contextId && $fieldHandle) {
            return [
                'type' => $contextType,
                'id' => $contextId,
                'field' => $fieldHandle,
            ];
        }

        return null;
    }

    private function queueBackgroundJob(string $jobId, string $action, ?array $context, Closure $dispatch): JsonResponse
    {
        Log::info('Job created', [
            'job_id' => $jobId,
            'action' => $action,
            'context' => $context,
        ]);

        // Use JobTracker if context is provided, otherwise fall back to simple cache
        if ($context) {
            $this->jobTracker->createJob(
                $jobId,
                $action,
                $context['type'],
                $context['id'],
                $context['field']
            );
        } else {
            Cache::put(JobTracker::CACHE_PREFIX.$jobId, [
                'status' => 'queued',
                'message' => 'Job has been queued',
            ], JobTracker::JOB_TTL);
        }

        $dispatch();

        Log::info('Job dispatched', [
            'job_id' => $jobId,
            'action' => $action,
        ]);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'context' => $context,
        ]);
    }
}
