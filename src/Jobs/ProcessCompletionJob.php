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

final class ProcessCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $jobId;

    private string $promptHandle;

    private array $variables;

    /**
     * Create a new job instance.
     */
    public function __construct(string $jobId, string $promptHandle, array $variables)
    {
        $this->jobId = $jobId;
        $this->promptHandle = $promptHandle;
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
                'message' => 'Processing completion request...',
            ], 3600);

            // Get the parsed prompt with variables rendered
            $promptData = $promptsService->getParsedPromptWithVariables($this->promptHandle, $this->variables);

            if (! $promptData) {
                $this->handleError('Prompt not found or could not be parsed.');

                return;
            }

            // Log the prompt data for debugging
            Log::info('Parsed prompt data for completion:', [
                'job_id' => $this->jobId,
                'prompt_handle' => $this->promptHandle,
                'messages' => $promptData['messages'] ?? [],
                'model' => $promptData['model'] ?? null,
            ]);

            // Validate we have messages
            if (empty($promptData['messages'])) {
                Log::error('No messages found in parsed prompt data');
                $promptData['messages'] = [
                    [
                        'role' => 'user',
                        'content' => 'Please provide a response.',
                    ],
                ];
            }

            // Call the OpenAI service based on the provider in the prompt data
            $response = $openAIService->completion($promptData['messages'], $promptData['model']);

            $response = $promptsService->validateResponse($this->promptHandle, $response);

            if (! $response) {
                $this->handleError('Failed to get completion from API.');

                return;
            }

            // Store the result in cache
            Cache::put('magic_actions_job_'.$this->jobId, [
                'status' => 'completed',
                'data' => $response['data'],
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
