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
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

final class ProcessPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?MagicAction $loadedAction = null;

    public function __construct(
        private readonly string $jobId,
        private readonly string $action,
        private readonly array $variables,
        private readonly ?string $assetPath,
        private readonly array $context
    ) {}

    public function handle(ActionLoader $actionLoader, JobTracker $jobTracker): void
    {
        try {
            $this->updateJobStatus($jobTracker, 'processing', 'Processing request...');

            $promptData = $actionLoader->load($this->action, $this->variables);
            $action = $promptData['action'];
            $this->loadedAction = $action;

            $response = match ($promptData['type']) {
                'text', 'vision' => $this->handleTextPrompt($promptData, $action),
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
        $finalValue = $this->prepareValueForField($field, $value, $entry->get($fieldHandle), $this->loadedAction);

        $entry->set($fieldHandle, $finalValue);
        $entry->saveQuietly();

        Log::debug("Magic action: persisted to entry {$this->context['id']}");

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
        $finalValue = $this->prepareValueForField($field, $value, $asset->get($fieldHandle), $this->loadedAction);

        $asset->set($fieldHandle, $finalValue);
        $asset->saveQuietly();

        Log::info('Result persisted to asset', [
            'job_id' => $this->jobId,
            'asset_id' => $this->context['id'],
            'field' => $fieldHandle,
        ]);

        return $finalValue;
    }

    private function prepareValueForField(
        mixed $field,
        mixed $value,
        mixed $currentValue,
        ?MagicAction $action = null
    ): mixed {
        if ($field === null) {
            return $value;
        }

        $fieldType = $field->type();
        $config = $field->config();
        $mode = $config['magic_actions_mode'] ?? 'replace';

        if ($fieldType === 'bard' && is_string($value)) {
            $value = $this->wrapInBardBlock($value);
        }

        if ($fieldType === 'terms' && is_array($value)) {
            $value = $this->ensureTermsExist($value, $config, $action);
        }

        return $this->applyUpdateMode($currentValue, $value, $mode, $fieldType);
    }

    /**
     * Resolve taxonomy term slugs and optionally create missing terms.
     */
    private function ensureTermsExist(array $terms, array $config, ?MagicAction $action = null): array
    {
        $taxonomy = $config['taxonomy'] ?? ($config['taxonomies'][0] ?? null);
        $constrainToExistingTerms = $action?->constrainToExistingTerms() ?? false;

        if (! $taxonomy) {
            return $terms;
        }

        $taxonomyInstance = Taxonomy::findByHandle($taxonomy);
        if (! $taxonomyInstance) {
            return $terms;
        }

        return collect($terms)->map(function ($termValue) use ($taxonomy, $taxonomyInstance, $constrainToExistingTerms, $action) {
            $slug = Str::slug((string) $termValue);

            $existingTerm = Term::find("{$taxonomy}::{$slug}");
            if ($existingTerm) {
                return $slug;
            }

            if ($constrainToExistingTerms) {
                Log::debug('Dropped non-existing constrained taxonomy term', [
                    'job_id' => $this->jobId,
                    'taxonomy' => $taxonomy,
                    'slug' => $slug,
                    'term_value' => $termValue,
                    'action' => $action?->getHandle(),
                ]);

                return null;
            }

            $term = Term::make()
                ->slug($slug)
                ->taxonomy($taxonomyInstance)
                ->set('title', $termValue);

            $term->save();

            Log::info('Created new taxonomy term', [
                'job_id' => $this->jobId,
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'title' => $termValue,
            ]);

            return $slug;
        })->reject(static fn (mixed $slug): bool => $slug === null)->values()->all();
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

        if (in_array($fieldType, ['text', 'textarea'], true)) {
            $current = is_string($currentValue) ? $currentValue : '';
            $new = is_string($newValue) ? $newValue : '';

            if ($current === '') {
                return $new;
            }

            return $current."\n".$new;
        }

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

        $this->logRequestPayload($promptData, $hasSchema, $media);

        $request = $this->createTextRequest(
            $hasSchema ? Prism::structured() : Prism::text(),
            $promptData,
            $media
        );

        if ($hasSchema) {
            $result = $request->withSchema($promptData['schema'])->asStructured();
            $this->logResponse('structured');

            return $action->unwrap($result->structured);
        }

        $result = $request->asText();
        $this->logResponse('text');

        return $result->text;
    }

    private function createTextRequest(mixed $builder, array $promptData, array $media): mixed
    {
        $builder
            ->using($promptData['provider'], $promptData['model'])
            ->withSystemPrompt($promptData['systemPrompt']);

        if ($media === []) {
            $builder->withPrompt($promptData['userPrompt']);
        } else {
            $builder->withPrompt($promptData['userPrompt'], $media);
        }

        if (isset($promptData['parameters']['temperature'])) {
            $builder->usingTemperature($promptData['parameters']['temperature']);
        }
        if (isset($promptData['parameters']['max_tokens'])) {
            $builder->withMaxTokens($promptData['parameters']['max_tokens']);
        }

        return $builder;
    }

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

        Log::info('API request: Transcribing audio', [
            'job_id' => $this->jobId,
            'provider' => $promptData['provider'],
            'model' => $promptData['model'],
            'asset_path' => $asset->path(),
            'parameters' => $promptData['parameters'] ?? [],
        ]);

        $request = Prism::audio()
            ->using($promptData['provider'], $promptData['model'])
            ->withInput($audioFile);

        if (! empty($promptData['parameters'])) {
            $request->withProviderOptions($promptData['parameters']);
        }

        $result = $request->asText();
        $this->logResponse('audio');

        return $result->text;
    }

    private function resolveAsset(): ?Asset
    {
        if (! $this->assetPath) {
            return null;
        }

        return AssetFacade::find($this->assetPath);
    }

    private function isDocument(Asset $asset): bool
    {
        return in_array(
            $asset->extension(),
            ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'],
            true
        );
    }

    private function handleError(JobTracker $jobTracker, string $message): void
    {
        $jobTracker->updateStatus($this->jobId, 'failed', $message);
    }

    private function logRequestPayload(array $promptData, bool $hasSchema, array $media): void
    {
        $logData = [
            'job_id' => $this->jobId,
            'action' => $this->action,
            'provider' => $promptData['provider'],
            'model' => $promptData['model'],
            'has_schema' => $hasSchema,
            'has_media' => ! empty($media),
            'parameters' => $promptData['parameters'] ?? [],
        ];

        Log::debug('API request: Sending prompt to AI provider', $logData);
    }

    private function logResponse(string $type): void
    {
        $logData = [
            'job_id' => $this->jobId,
            'request_type' => $type,
            'response_source' => 'AI provider',
        ];

        Log::debug('API response: Received from AI provider', $logData);
    }
}
