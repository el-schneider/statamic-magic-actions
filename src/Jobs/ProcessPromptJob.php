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
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Entry;

final class ProcessPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $jobId,
        private string $action,
        private array $variables,
        private ?string $assetPath,
        private array $context
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

            $finalValue = $this->persistResult($response);
            $this->updateJobStatus($jobTracker, 'completed', null, $finalValue ?? $response);

        } catch (Exception $e) {
            Log::error('Job error', [
                'job_id' => $this->jobId,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
            $this->handleError($jobTracker, $e->getMessage());
        }
    }

    private function persistResult(mixed $response): mixed
    {
        $value = $this->extractResultValue($response);
        $fieldHandle = $this->context['field'];

        if ($this->context['type'] === 'entry') {
            return $this->persistToEntry($fieldHandle, $value);
        }

        if ($this->context['type'] === 'asset') {
            return $this->persistToAsset($fieldHandle, $value);
        }

        return null;
    }

    private function extractResultValue(mixed $response): mixed
    {
        if (is_array($response) && isset($response['text'])) {
            return $response['text'];
        }

        if (is_string($response)) {
            return $response;
        }

        return $response;
    }

    private function persistToEntry(string $fieldHandle, mixed $value): mixed
    {
        $entry = Entry::find($this->context['id']);

        if (! $entry) {
            Log::warning('Entry not found for persistence', [
                'job_id' => $this->jobId,
                'entry_id' => $this->context['id'],
            ]);

            return null;
        }

        $blueprint = $entry->blueprint();
        $field = $blueprint?->field($fieldHandle);
        $finalValue = $this->prepareValueForField($field, $value, $entry->get($fieldHandle));

        $entry->set($fieldHandle, $finalValue);
        $entry->saveQuietly();

        Log::info('Result persisted to entry', [
            'job_id' => $this->jobId,
            'entry_id' => $this->context['id'],
            'field' => $fieldHandle,
        ]);

        return $finalValue;
    }

    private function persistToAsset(string $fieldHandle, mixed $value): mixed
    {
        $asset = AssetFacade::find($this->context['id']);

        if (! $asset) {
            Log::warning('Asset not found for persistence', [
                'job_id' => $this->jobId,
                'asset_id' => $this->context['id'],
            ]);

            return null;
        }

        $blueprint = $asset->blueprint();
        $field = $blueprint?->field($fieldHandle);
        $finalValue = $this->prepareValueForField($field, $value, $asset->get($fieldHandle));

        $asset->set($fieldHandle, $finalValue);
        $asset->saveQuietly();

        Log::info('Result persisted to asset', [
            'job_id' => $this->jobId,
            'asset_id' => $this->context['id'],
            'field' => $fieldHandle,
        ]);

        return $finalValue;
    }

    private function prepareValueForField(mixed $field, mixed $value, mixed $currentValue): mixed
    {
        if (! $field) {
            return $value;
        }

        $fieldType = $field->type();
        $config = $field->config();
        $mode = $config['magic_actions_mode'] ?? 'replace';

        if ($fieldType === 'bard' && is_string($value)) {
            $value = $this->wrapInBardBlock($value);
        }

        return $this->applyUpdateMode($currentValue, $value, $mode, $fieldType);
    }

    private function wrapInBardBlock(string $text): array
    {
        return [
            [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    private function applyUpdateMode(mixed $currentValue, mixed $newValue, string $mode, string $fieldType): mixed
    {
        if ($mode !== 'append') {
            return $newValue;
        }

        // Handle text-based fields (text, textarea)
        if (in_array($fieldType, ['text', 'textarea'])) {
            $current = is_string($currentValue) ? $currentValue : '';
            $new = is_string($newValue) ? $newValue : '';

            if ($current === '') {
                return $new;
            }

            return $current."\n".$new;
        }

        // Handle array-based fields (terms, bard, etc.)
        if (is_array($currentValue)) {
            return array_merge($currentValue, is_array($newValue) ? $newValue : [$newValue]);
        }

        return $newValue;
    }

    private function updateJobStatus(JobTracker $jobTracker, string $status, ?string $message = null, mixed $data = null): void
    {
        $jobTracker->updateStatus($this->jobId, $status, $message, $data);
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

    private function handleError(JobTracker $jobTracker, string $message): void
    {
        $jobTracker->updateStatus($this->jobId, 'failed', $message);
    }
}
