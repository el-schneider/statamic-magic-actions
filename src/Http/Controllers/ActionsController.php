<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

use Closure;
use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Jobs\ProcessCompletionJob;
use ElSchneider\StatamicMagicActions\Jobs\ProcessTranscriptionJob;
use ElSchneider\StatamicMagicActions\Jobs\ProcessVisionJob;
use ElSchneider\StatamicMagicActions\Services\PromptsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ActionsController extends Controller
{
    private PromptsService $promptsService;

    public function __construct(PromptsService $promptsService)
    {
        $this->promptsService = $promptsService;
    }

    /**
     * Start a completion job
     */
    public function completion(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'text' => 'required|string',
                'prompt' => 'required|string',
            ]);

            $text = $request->input('text');
            $promptHandle = $request->input('prompt');

            if (! $this->promptsService->promptExists($promptHandle)) {
                return response()->json(['error' => 'Prompt not found'], 404);
            }

            $jobId = (string) Str::uuid();

            return $this->queueBackgroundJob($jobId, $promptHandle, function () use ($jobId, $promptHandle, $text) {
                ProcessCompletionJob::dispatch($jobId, $promptHandle, [
                    'text' => $text,
                ]);
            });
        } catch (MissingApiKeyException) {
            return $this->apiKeyNotConfiguredError('Completion');
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
                'prompt' => 'required|string',
                'variables' => 'sometimes|array',
            ]);

            $assetPath = $request->input('asset_path');
            $promptHandle = $request->input('prompt');
            $variables = $request->input('variables', []);

            if (! $this->promptsService->promptExists($promptHandle)) {
                return response()->json(['error' => 'Prompt not found'], 404);
            }

            $jobId = (string) Str::uuid();

            return $this->queueBackgroundJob($jobId, $promptHandle, function () use ($jobId, $promptHandle, $assetPath, $variables) {
                ProcessVisionJob::dispatch($jobId, $promptHandle, $assetPath, $variables);
            });
        } catch (MissingApiKeyException) {
            return $this->apiKeyNotConfiguredError('Vision');
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
                'prompt' => 'required|string',
            ]);

            $assetPath = $request->input('asset_path');
            $promptHandle = $request->input('prompt');

            if (! $this->promptsService->promptExists($promptHandle)) {
                return response()->json(['error' => 'Prompt not found'], 404);
            }

            $jobId = (string) Str::uuid();

            return $this->queueBackgroundJob($jobId, $promptHandle, function () use ($jobId, $promptHandle, $assetPath) {
                ProcessTranscriptionJob::dispatch($jobId, $promptHandle, $assetPath);
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
        // Log the request for debugging
        Log::info('Job status request received', [
            'job_id' => $jobId,
            'cache_key' => 'magic_actions_job_'.$jobId,
        ]);

        $job = Cache::get('magic_actions_job_'.$jobId);

        if (! $job) {
            Log::warning('Job not found in cache', ['job_id' => $jobId]);

            return response()->json(['error' => 'Job not found'], 404);
        }

        Log::info('Job status found', [
            'job_id' => $jobId,
            'status' => $job['status'],
        ]);

        return response()->json($job);
    }

    private function apiKeyNotConfiguredError(string $action): JsonResponse
    {
        Log::warning("$action request rejected: OpenAI API key not configured");

        return response()->json([
            'error' => 'OpenAI API key is not configured',
            'message' => 'Please configure your OpenAI API key in the addon settings',
        ], 500);
    }

    private function queueBackgroundJob(string $jobId, string $promptHandle, Closure $dispatch): JsonResponse
    {
        Log::info('Job created', [
            'job_id' => $jobId,
            'prompt_handle' => $promptHandle,
        ]);

        Cache::put('magic_actions_job_'.$jobId, [
            'status' => 'queued',
            'message' => 'Job has been queued',
        ], 3600);

        $cachedJob = Cache::get('magic_actions_job_'.$jobId);
        Log::info('Job cache status', [
            'job_id' => $jobId,
            'cache_exists' => $cachedJob !== null,
            'cache_data' => $cachedJob,
        ]);

        $dispatch();

        Log::info('Job dispatched', [
            'job_id' => $jobId,
            'prompt_handle' => $promptHandle,
        ]);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued',
        ]);
    }
}
