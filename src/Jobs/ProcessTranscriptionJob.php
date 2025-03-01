<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Services\AssetsService;
use ElSchneider\StatamicMagicActions\Services\OpenAIService;
use ElSchneider\StatamicMagicActions\Services\PromptsService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Asset;

final class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $jobId;

    private string $promptHandle;

    private string $assetId;

    private array $variables;

    /**
     * Create a new job instance.
     */
    public function __construct(string $jobId, string $promptHandle, string $assetId, array $variables = [])
    {
        $this->jobId = $jobId;
        $this->promptHandle = $promptHandle;
        $this->assetId = $assetId;
        $this->variables = $variables;
    }

    /**
     * Execute the job.
     */
    public function handle(PromptsService $promptsService, OpenAIService $openAIService, AssetsService $assetsService): void
    {
        try {
            // Update job status to "processing"
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'processing',
                'message' => 'Processing transcription request...',
            ], 3600);

            // Get asset data
            $asset = $assetsService->getAssetById($this->assetId);

            if (! $asset) {
                $this->handleError('Asset not found.');

                return;
            }

            // Get the parsed prompt with variables rendered
            $promptData = $promptsService->getParsedPromptWithVariables($this->promptHandle, $this->variables);

            if (! $promptData) {
                $this->handleError('Prompt not found or could not be parsed.');

                return;
            }

            // Call the OpenAI service
            $response = $openAIService->transcribe($asset, $promptData['model'] ?? null);

            if (! $response) {
                $this->handleError('Failed to get transcription from API.');

                return;
            }

            // Store the result in cache
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'completed',
                'data' => $response,
            ], 3600);
        } catch (Exception $e) {
            $this->handleError('Error processing transcription: '.$e->getMessage());
        }
    }

    /**
     * Handle job error
     */
    private function handleError(string $message): void
    {
        Log::error($message);

        Cache::put('magic_actions_job_'.$this->jobId, [
            'status' => 'failed',
            'error' => $message,
        ], 3600);
    }
}
