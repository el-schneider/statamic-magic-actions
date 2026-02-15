<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Stringable;
use Throwable;

final class ExtractTags extends Action
{
    protected $confirm = true;

    /**
     * @var array<string, array<string, string>>
     */
    private array $configuredFieldCache = [];

    public function __construct(private readonly ActionExecutor $actionExecutor)
    {
        parent::__construct();
    }

    public static function title()
    {
        return __('Extract Tags');
    }

    public function visibleTo($item)
    {
        if (($this->context['view'] ?? 'list') !== 'list') {
            return false;
        }

        if (! $item instanceof Entry) {
            return false;
        }

        return $this->configuredFieldOptionsForEntry($item) !== [];
    }

    public function visibleToBulk($items)
    {
        if ($items->whereInstanceOf(Entry::class)->count() !== $items->count()) {
            return false;
        }

        return $items->contains(fn ($item): bool => $item instanceof Entry && $this->configuredFieldOptionsForEntry($item) !== []);
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Extract tags for this entry?|Extract tags for these :count entries?';
    }

    public function buttonText()
    {
        /** @translation */
        return 'Extract Tags|Extract Tags for :count Entries';
    }

    public function run($items, $values)
    {
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
                $this->actionExecutor->execute(
                    'extract-tags',
                    $item,
                    $targetFieldHandle,
                    ['variables' => ['text' => $sourceText]]
                );

                $queued++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Bulk extract tags action failed for entry', [
                    'entry_id' => (string) $item->id(),
                    'field' => $targetFieldHandle,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultMessage($queued, $skipped, $failed);
    }

    protected function fieldItems()
    {
        if (! $this->requiresFieldSelection()) {
            return [];
        }

        $options = $this->availableFieldOptions();

        if (count($options) <= 1) {
            return [];
        }

        return [
            'field_handle' => [
                'display' => __('Tag Field'),
                'instructions' => __('Select the field that should receive extracted tags.'),
                'type' => 'select',
                'options' => $options,
                'validate' => 'required',
            ],
        ];
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

    private function requiresFieldSelection(): bool
    {
        foreach ($this->items as $item) {
            if (! $item instanceof Entry) {
                continue;
            }

            if (count($this->configuredFieldOptionsForEntry($item)) > 1) {
                return true;
            }
        }

        return false;
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

        if (isset($this->configuredFieldCache[$cacheKey])) {
            return $this->configuredFieldCache[$cacheKey];
        }

        $configuredFields = [];

        foreach ($blueprint->fields()->all() as $field) {
            $config = $field->config();

            if (! is_array($config)) {
                continue;
            }

            if (! ($config['magic_actions_enabled'] ?? false)) {
                continue;
            }

            $actions = $this->normalizeConfiguredActions($config['magic_actions_action'] ?? null);
            if (! in_array('extract-tags', $actions, true)) {
                continue;
            }

            $configuredFields[(string) $field->handle()] = (string) $field->display();
        }

        $this->configuredFieldCache[$cacheKey] = $configuredFields;

        return $configuredFields;
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

    private function resultMessage(int $queued, int $skipped, int $failed): string
    {
        $messages = [];

        if ($queued > 0) {
            $messages[] = trans_choice(
                'Queued tag extraction for :count entry.|Queued tag extraction for :count entries.',
                $queued,
                ['count' => $queued]
            );
        }

        if ($skipped > 0) {
            $messages[] = trans_choice(
                ':count entry was skipped.|:count entries were skipped.',
                $skipped,
                ['count' => $skipped]
            );
        }

        if ($failed > 0) {
            $messages[] = trans_choice(
                ':count entry failed to queue.|:count entries failed to queue.',
                $failed,
                ['count' => $failed]
            );
        }

        return $messages === [] ? __('No entries were processed.') : implode(' ', $messages);
    }
}
