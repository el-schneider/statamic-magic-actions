<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
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

    public function handle(ActionLoader $actionLoader): void
    {
        try {
            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'processing',
                'message' => 'Processing request...',
            ], 3600);

            $promptData = $actionLoader->load($this->action, $this->variables);
            $action = $promptData['action'];

            $response = match ($promptData['type']) {
                'text' => $this->handleTextPrompt($promptData, $action),
                'audio' => $this->handleAudioPrompt($promptData),
                default => throw new Exception("Unknown prompt type: {$promptData['type']}"),
            };

            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'completed',
                'data' => $response,
            ], 3600);

        } catch (Exception $e) {
            Log::error('Job error', [
                'job_id' => $this->jobId,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
            $this->handleError($e->getMessage());
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

        return $request->asText()->text;
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

    private function handleError(string $message): void
    {
        Cache::put("magic_actions_job_{$this->jobId}", [
            'status' => 'failed',
            'error' => $message,
        ], 3600);
    }
}
