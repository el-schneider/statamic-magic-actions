<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Exceptions\OpenAIApiException;
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

    private string $assetPath;

    private array $variables;

    /**
     * Create a new job instance.
     */
    public function __construct(string $jobId, string $promptHandle, string $assetPath, array $variables = [])
    {
        $this->jobId = $jobId;
        $this->promptHandle = $promptHandle;
        $this->assetPath = $assetPath;
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
            $asset = $assetsService->getAssetByPath($this->assetPath);

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
            $response = $openAIService->transcribe(
                $asset,
                $promptData['model'] ?? null,
            );

            if (! $response) {
                $this->handleError('Failed to get transcription from API.');

                return;
            }

            // Store the result in cache
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'completed',
                'data' => $response['text'],
            ], 3600);
        } catch (OpenAIApiException $e) {
            Log::error('OpenAI API error', ['error' => $e->getMessage()]);
            $this->handleError('An error occurred with the API request. Please check the logs for details.');
        } catch (Exception $e) {
            Log::error('Job error', ['error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
        }
    }

    /**
     * Handle job error
     */
    private function handleError(string $message): void
    {
        Cache::put('magic_actions_job_'.$this->jobId, [
            'status' => 'failed',
            'error' => $message,
        ], 3600);
    }
}
