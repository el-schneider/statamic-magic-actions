<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

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
        $request->validate([
            'text' => 'required|string',
            'prompt' => 'required|string',
        ]);

        $text = $request->input('text');
        $promptHandle = $request->input('prompt');

        // Check if prompt exists
        if (! $this->promptsService->promptExists($promptHandle)) {
            return response()->json(['error' => 'Prompt not found'], 404);
        }

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        Log::info('Completion job created', [
            'job_id' => $jobId,
            'prompt_handle' => $promptHandle,
        ]);

        // Set initial status
        Cache::put('magic_actions_job_'.$jobId, [
            'status' => 'queued',
            'message' => 'Job has been queued',
        ], 3600);

        // Verify the cache entry was created
        $cachedJob = Cache::get('magic_actions_job_'.$jobId);
        Log::info('Job cache status', [
            'job_id' => $jobId,
            'cache_exists' => ! is_null($cachedJob),
            'cache_data' => $cachedJob,
        ]);

        // Dispatch job
        ProcessCompletionJob::dispatch($jobId, $promptHandle, [
            'text' => $text,
        ]);

        Log::info('Completion job dispatched', [
            'job_id' => $jobId,
            'prompt_handle' => $promptHandle,
        ]);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued',
        ]);
    }

    /**
     * Start a vision job
     */
    public function vision(Request $request): JsonResponse
    {
        $request->validate([
            'asset_path' => 'required|string',
            'prompt' => 'required|string',
            'variables' => 'sometimes|array',
        ]);

        $assetPath = $request->input('asset_path');
        $promptHandle = $request->input('prompt');
        $variables = $request->input('variables', []);

        // Check if prompt exists
        if (! $this->promptsService->promptExists($promptHandle)) {
            return response()->json(['error' => 'Prompt not found'], 404);
        }

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        // Set initial status
        Cache::put('magic_actions_job_'.$jobId, [
            'status' => 'queued',
            'message' => 'Job has been queued',
        ], 3600);

        // Dispatch job
        ProcessVisionJob::dispatch($jobId, $promptHandle, $assetPath, $variables);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued',
        ]);
    }

    /**
     * Start a transcription job
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'asset_path' => 'required|string',
            'prompt' => 'required|string',
        ]);

        $assetPath = $request->input('asset_path');
        $promptHandle = $request->input('prompt');

        // Check if prompt exists
        if (! $this->promptsService->promptExists($promptHandle)) {
            return response()->json(['error' => 'Prompt not found'], 404);
        }

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        // Set initial status
        Cache::put('magic_actions_job_'.$jobId, [
            'status' => 'queued',
            'message' => 'Job has been queued',
        ], 3600);

        // Dispatch job
        ProcessTranscriptionJob::dispatch($jobId, $promptHandle, $assetPath);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued',
        ]);
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
}
