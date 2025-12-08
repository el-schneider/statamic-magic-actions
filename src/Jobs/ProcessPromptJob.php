<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Statamic\Facades\Asset as AssetFacade;

final class ProcessPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $jobId,
        private string $action,
        private array $variables,
        private ?string $assetPath = null
    ) {}

    public function handle(ActionLoader $actionLoader, JobTracker $jobTracker): void
    {
        try {
            $this->updateJobStatus($jobTracker, 'processing', 'Processing request...');

            $promptData = $actionLoader->load($this->action, $this->variables);
            $action = $promptData['action'];

            $response = match ($promptData['type']) {
                'text' => $this->handleTextPrompt($promptData, $action),
                'audio' => $this->handleAudioPrompt($promptData),
                default => throw new Exception("Unknown prompt type: {$promptData['type']}"),
            };

            $this->updateJobStatus($jobTracker, 'completed', null, $response);

        } catch (Exception $e) {
            Log::error('Job error', [
                'job_id' => $this->jobId,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
            $this->handleError($jobTracker, $e->getMessage());
        }
    }

    /**
     * Update job status using JobTracker if job has context, otherwise use simple cache.
     */
    private function updateJobStatus(JobTracker $jobTracker, string $status, ?string $message = null, mixed $data = null): void
    {
        // Try to get existing job from JobTracker first (has context)
        $existingJob = $jobTracker->getJob($this->jobId);

        if ($existingJob && isset($existingJob['context'])) {
            // Job was created with context, use JobTracker
            $jobTracker->updateStatus($this->jobId, $status, $message, $data);
        } else {
            // Fallback to simple cache for backwards compatibility
            $cacheData = ['status' => $status];

            if ($message !== null) {
                $cacheData['message'] = $message;
            }

            if ($data !== null) {
                $cacheData['data'] = $data;
            }

            Cache::put("magic_actions_job_{$this->jobId}", $cacheData, 3600);
        }
    }

    private function handleTextPrompt(array $promptData, MagicAction $action): mixed
    {
        $media = $this->loadMediaFromAsset();
        $hasSchema = isset($promptData['schema']);

        $request = $this->createTextRequest(
            $hasSchema ? Prism::structured() : Prism::text(),
            $promptData,
            $media
        );

        if ($hasSchema) {
            $result = $request->withSchema($promptData['schema'])->asStructured();

            return $action->unwrap($result->structured);
        }

        return ['text' => $request->asText()->text];
    }

    private function createTextRequest(mixed $builder, array $promptData, array $media): mixed
    {
        $builder
            ->using($promptData['provider'], $promptData['model'])
            ->withSystemPrompt($promptData['systemPrompt']);

        empty($media)
            ? $builder->withPrompt($promptData['userPrompt'])
            : $builder->withPrompt($promptData['userPrompt'], $media);

        if (isset($promptData['parameters']['temperature'])) {
            $builder->usingTemperature($promptData['parameters']['temperature']);
        }
        if (isset($promptData['parameters']['max_tokens'])) {
            $builder->withMaxTokens($promptData['parameters']['max_tokens']);
        }

        return $builder;
    }

    /**
     * Load media from Statamic asset using fromStoragePath
     */
    private function loadMediaFromAsset(): array
    {
        $asset = $this->resolveAsset();
        if (! $asset) {
            return [];
        }

        $path = $asset->path();
        $disk = $asset->container()->diskHandle();

        if ($asset->isImage()) {
            return [Image::fromStoragePath($path, $disk)];
        }

        if ($this->isDocument($asset)) {
            return [Document::fromStoragePath($path, $disk)];
        }

        return [];
    }

    private function handleAudioPrompt(array $promptData): string
    {
        $asset = $this->resolveAsset();
        if (! $asset) {
            throw new Exception('Audio asset not found');
        }

        $audioFile = Audio::fromStoragePath(
            $asset->path(),
            $asset->container()->diskHandle()
        );

        $request = Prism::audio()
            ->using($promptData['provider'], $promptData['model'])
            ->withInput($audioFile);

        if (! empty($promptData['parameters'])) {
            $request->withProviderOptions($promptData['parameters']);
        }

        return $request->asText()->text;
    }

    /**
     * @return \Statamic\Assets\Asset|null
     */
    private function resolveAsset(): mixed
    {
        if (! $this->assetPath) {
            return null;
        }

        return AssetFacade::find($this->assetPath);
    }

    /**
     * @param  \Statamic\Assets\Asset  $asset
     */
    private function isDocument(mixed $asset): bool
    {
        return in_array(
            $asset->extension(),
            ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt']
        );
    }

    private function handleError(JobTracker $jobTracker, string $message): void
    {
        // Try to get existing job from JobTracker first (has context)
        $existingJob = $jobTracker->getJob($this->jobId);

        if ($existingJob && isset($existingJob['context'])) {
            // Job was created with context, use JobTracker
            $jobTracker->updateStatus($this->jobId, 'failed', $message);
        } else {
            // Fallback to simple cache for backwards compatibility
            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'failed',
                'error' => $message,
            ], 3600);
        }
    }
}
