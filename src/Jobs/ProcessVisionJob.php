<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Exceptions\OpenAIApiException;
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

final class ProcessVisionJob implements ShouldQueue
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
    public function handle(PromptsService $promptsService, OpenAIService $openAIService): void
    {
        try {
            // Update job status to "processing"
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'processing',
                'message' => 'Processing vision request...',
            ], 3600);

            // Get the asset
            $asset = Asset::find($this->assetId);

            if (! $asset) {
                $this->handleError('Asset not found.');

                return;
            }

            // Get the image URL
            $imageUrl = $asset->url();

            if (! $imageUrl) {
                $this->handleError('Could not get image URL.');

                return;
            }

            // Get the parsed prompt with variables rendered
            $promptData = $promptsService->getParsedPromptWithVariables($this->promptHandle, $this->variables);

            if (! $promptData) {
                $this->handleError('Prompt not found or could not be parsed.');

                return;
            }

            // Log the parsed messages for debugging
            Log::info('Vision job messages before calling API:', [
                'job_id' => $this->jobId,
                'messages' => $promptData['messages'],
            ]);

            // Make sure at least one message exists with content for image
            if (empty($promptData['messages'])) {
                $promptData['messages'][] = [
                    'role' => 'user',
                    'content' => 'Please analyze this image and provide details about what you see.',
                ];
            }

            // Call the OpenAI service
            $response = $openAIService->vision($promptData['messages'], $imageUrl, $promptData['model']);

            if (! $response) {
                $this->handleError('Failed to get vision analysis from API.');

                return;
            }

            // Store the result in cache
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'completed',
                'data' => $response,
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
