<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Stringable;
use Throwable;

/**
 * A single Action class that adapts any MagicAction into a Statamic bulk action.
 *
 * Each instance is configured with a MagicAction handle. All metadata (title,
 * confirmation text, visibility, etc.) is read from the MagicAction itself.
 *
 * To add bulk support to a new MagicAction, just return true from supportsBulk().
 */
final class DynamicBulkAction extends Action
{
    private const array IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'bmp', 'avif', 'tiff', 'tif', 'heic', 'heif',
    ];

    private const array AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'webm',
    ];

    protected $confirm = true;

    private string $magicActionHandle = '';

    private ?MagicAction $magicAction = null;

    /**
     * @var array<string, array<string, string>>
     */
    private array $configuredFieldCache = [];

    public static function title()
    {
        return '';
    }

    public function setMagicActionHandle(string $handle): self
    {
        $this->magicActionHandle = $handle;
        $this->magicAction = null;

        return $this;
    }

    /**
     * Override toArray to inject the real handle and title from MagicAction.
     */
    public function toArray()
    {
        $array = parent::toArray();
        $magic = $this->resolveMagicAction();

        $array['handle'] = 'magic-bulk-'.$this->magicActionHandle;

        if ($magic !== null) {
            $array['title'] = $magic->getTitle();
        }

        return $array;
    }

    public function confirmationText()
    {
        $magic = $this->resolveMagicAction();

        return $magic?->bulkConfirmationText()
            ?? parent::confirmationText();
    }

    public function buttonText()
    {
        $magic = $this->resolveMagicAction();

        return $magic?->bulkButtonText()
            ?? parent::buttonText();
    }

    public function visibleTo($item)
    {
        if (($this->context['view'] ?? 'list') !== 'list') {
            return false;
        }

        $magic = $this->resolveMagicAction();

        if ($magic === null) {
            return false;
        }

        if ($magic->bulkTargetType() === 'asset') {
            return $item instanceof Asset && $this->isCompatibleAsset($item, $magic);
        }

        if (! $item instanceof Entry) {
            return false;
        }

        return $this->configuredFieldOptionsForEntry($item) !== [];
    }

    public function visibleToBulk($items)
    {
        return collect($items)->contains(fn ($item): bool => $this->visibleTo($item));
    }

    public function run($items, $values)
    {
        $magic = $this->resolveMagicAction();

        if ($magic === null) {
            return __('magic-actions::magic-actions.bulk.action_not_found');
        }

        if ($magic->bulkTargetType() === 'asset') {
            return $this->runForAssets($items, $magic);
        }

        return $this->runForEntries($items, $values, $magic);
    }

    protected function fieldItems()
    {
        $magic = $this->resolveMagicAction();

        if ($magic === null || ! $magic->supportsFieldSelection()) {
            return [];
        }

        $options = $this->availableFieldOptions();

        if (count($options) <= 1) {
            return [];
        }

        return [
            'field_handle' => [
                'display' => __('magic-actions::magic-actions.bulk.target_field.display'),
                'instructions' => __('magic-actions::magic-actions.bulk.target_field.instructions'),
                'type' => 'select',
                'options' => $options,
                'validate' => 'required',
            ],
        ];
    }

    private function runForAssets($items, MagicAction $magic): string
    {
        $executor = app(ActionExecutor::class);
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (! $item instanceof Asset || ! $this->isCompatibleAsset($item, $magic)) {
                $skipped++;

                continue;
            }

            $fieldHandle = $this->resolveAssetFieldHandle($item, $magic);

            if ($fieldHandle === null) {
                $skipped++;

                continue;
            }

            try {
                $executor->execute($this->magicActionHandle, $item, $fieldHandle);
                $queued++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning("Bulk action {$this->magicActionHandle} failed for asset", [
                    'asset_id' => (string) $item->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultMessage($queued, $skipped, $failed, $magic);
    }

    private function runForEntries($items, $values, MagicAction $magic): string
    {
        $executor = app(ActionExecutor::class);
        $selectedFieldHandle = $this->normalizeFieldHandle($values['field_handle'] ?? null);
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (! $item instanceof Entry) {
                $skipped++;

                continue;
            }

            $configuredFields = $this->configuredFieldOptionsForEntry($item);

            if ($configuredFields === []) {
                $skipped++;

                continue;
            }

            $targetFieldHandle = $this->resolveTargetFieldHandle($configuredFields, $selectedFieldHandle);

            if ($targetFieldHandle === null) {
                $skipped++;

                continue;
            }

            $sourceText = $this->resolveSourceText($item, $targetFieldHandle);

            if ($sourceText === '') {
                $skipped++;

                continue;
            }

            try {
                $executor->execute(
                    $this->magicActionHandle,
                    $item,
                    $targetFieldHandle,
                    ['variables' => ['text' => $sourceText]]
                );
                $queued++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning("Bulk action {$this->magicActionHandle} failed for entry", [
                    'entry_id' => (string) $item->id(),
                    'field' => $targetFieldHandle,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultMessage($queued, $skipped, $failed, $magic);
    }

    private function isCompatibleAsset(Asset $asset, MagicAction $magic): bool
    {
        $mimeTypes = $magic->acceptedMimeTypes();

        if ($mimeTypes === []) {
            return true;
        }

        $assetMime = mb_strtolower(mb_trim((string) $asset->mimeType()));
        $hasImageMime = false;
        $hasAudioMime = false;

        foreach ($mimeTypes as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            $pattern = mb_strtolower(mb_trim($pattern));
            $hasImageMime = $hasImageMime || str_starts_with($pattern, 'image/');
            $hasAudioMime = $hasAudioMime || str_starts_with($pattern, 'audio/');

            if (str_ends_with($pattern, '/*')) {
                $prefix = mb_substr($pattern, 0, -1);
                if (str_starts_with($assetMime, $prefix)) {
                    return true;
                }
            } elseif ($assetMime === $pattern) {
                return true;
            }
        }

        $ext = mb_strtolower(mb_trim((string) $asset->extension()));

        if ($hasImageMime && in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return true;
        }

        if ($hasAudioMime && in_array($ext, self::AUDIO_EXTENSIONS, true)) {
            return true;
        }

        return $hasImageMime && $asset->isImage();
    }

    private function resolveAssetFieldHandle(Asset $asset, MagicAction $magic): ?string
    {
        $blueprint = $asset->blueprint();

        if ($blueprint) {
            foreach ($blueprint->fields()->all() as $field) {
                $config = $field->config();

                if (! is_array($config) || ! ($config['magic_actions_enabled'] ?? false)) {
                    continue;
                }

                $actions = $this->normalizeConfiguredActions($config['magic_actions_action'] ?? null);

                if (in_array($this->magicActionHandle, $actions, true)) {
                    $handle = (string) $field->handle();

                    if ($handle !== '') {
                        return $handle;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function configuredFieldOptionsForEntry(Entry $entry): array
    {
        $blueprint = $entry->blueprint();

        if (! $blueprint) {
            return [];
        }

        $cacheKey = $blueprint->fullyQualifiedHandle();

        if ($cacheKey === '') {
            $cacheKey = spl_object_hash($blueprint);
        }

        $fullKey = $this->magicActionHandle.':'.$cacheKey;

        if (isset($this->configuredFieldCache[$fullKey])) {
            return $this->configuredFieldCache[$fullKey];
        }

        $configuredFields = [];

        foreach ($blueprint->fields()->all() as $field) {
            $config = $field->config();

            if (! is_array($config) || ! ($config['magic_actions_enabled'] ?? false)) {
                continue;
            }

            $actions = $this->normalizeConfiguredActions($config['magic_actions_action'] ?? null);

            if (! in_array($this->magicActionHandle, $actions, true)) {
                continue;
            }

            $configuredFields[(string) $field->handle()] = (string) $field->display();
        }

        $this->configuredFieldCache[$fullKey] = $configuredFields;

        return $configuredFields;
    }

    /**
     * @return array<string, string>
     */
    private function availableFieldOptions(): array
    {
        $options = [];

        foreach ($this->items as $item) {
            if (! $item instanceof Entry) {
                continue;
            }

            foreach ($this->configuredFieldOptionsForEntry($item) as $fieldHandle => $fieldDisplay) {
                if (! isset($options[$fieldHandle])) {
                    $options[$fieldHandle] = $fieldDisplay;
                }
            }
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $configuredFields
     */
    private function resolveTargetFieldHandle(array $configuredFields, ?string $selectedFieldHandle): ?string
    {
        if ($selectedFieldHandle !== null) {
            return array_key_exists($selectedFieldHandle, $configuredFields) ? $selectedFieldHandle : null;
        }

        if (count($configuredFields) === 1) {
            return array_key_first($configuredFields);
        }

        return null;
    }

    private function resolveSourceText(Entry $entry, string $fieldHandle): string
    {
        $blueprint = $entry->blueprint();
        $field = $blueprint?->field($fieldHandle);
        $config = $field?->config();

        if (is_array($config)) {
            $sourceHandle = $config['magic_actions_source'] ?? null;

            if (is_string($sourceHandle) && mb_trim($sourceHandle) !== '') {
                return $this->extractText($entry->get($sourceHandle));
            }
        }

        $content = $entry->get('content');

        if ($content !== null) {
            return $this->extractText($content);
        }

        $data = $entry->data();

        if (is_object($data) && method_exists($data, 'all')) {
            try {
                return $this->extractText($data->all());
            } catch (Throwable) {
                return '';
            }
        }

        return is_array($data) ? $this->extractText($data) : '';
    }

    private function extractText(mixed $content): string
    {
        if ($content === null) {
            return '';
        }

        if (is_string($content)) {
            return mb_trim($content);
        }

        if (is_scalar($content) || $content instanceof Stringable) {
            return mb_trim((string) $content);
        }

        if (is_array($content)) {
            if (($content['type'] ?? null) === 'text' && isset($content['text']) && is_string($content['text'])) {
                return mb_trim($content['text']);
            }

            $fragments = [];

            foreach ($content as $key => $value) {
                if (is_string($key) && in_array($key, ['type', 'attrs', 'marks'], true)) {
                    continue;
                }

                $text = $this->extractText($value);

                if ($text !== '') {
                    $fragments[] = $text;
                }
            }

            return implode("\n", $fragments);
        }

        if (is_object($content)) {
            if (method_exists($content, 'toArray')) {
                try {
                    $array = $content->toArray();

                    if (is_array($array)) {
                        return $this->extractText($array);
                    }
                } catch (Throwable) {
                    return '';
                }
            }

            return $this->extractText(get_object_vars($content));
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function normalizeConfiguredActions(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = mb_trim($configured);

            return $configured !== '' ? [$configured] : [];
        }

        if (! is_array($configured)) {
            return [];
        }

        $normalized = [];

        foreach ($configured as $action) {
            if (! is_string($action)) {
                continue;
            }

            $action = mb_trim($action);

            if ($action === '') {
                continue;
            }

            $normalized[$action] = $action;
        }

        return array_values($normalized);
    }

    private function normalizeFieldHandle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    private function resultMessage(int $queued, int $skipped, int $failed, MagicAction $magic): string
    {
        $messages = [];

        if ($queued > 0) {
            $messages[] = trans_choice(
                'magic-actions::magic-actions.bulk.result.queued',
                $queued,
                ['count' => $queued, 'action' => $magic->getTitle()]
            );
        }

        if ($skipped > 0) {
            $messages[] = trans_choice(
                'magic-actions::magic-actions.bulk.result.skipped',
                $skipped,
                ['count' => $skipped]
            );
        }

        if ($failed > 0) {
            $messages[] = trans_choice(
                'magic-actions::magic-actions.bulk.result.failed_to_queue',
                $failed,
                ['count' => $failed]
            );
        }

        return $messages === []
            ? __('magic-actions::magic-actions.bulk.result.none_processed')
            : implode(' ', $messages);
    }

    private function resolveMagicAction(): ?MagicAction
    {
        if ($this->magicAction !== null) {
            return $this->magicAction;
        }

        if ($this->magicActionHandle === '') {
            return null;
        }

        $registry = app(ActionRegistry::class);
        $this->magicAction = $registry->getInstance($this->magicActionHandle);

        return $this->magicAction;
    }
}
