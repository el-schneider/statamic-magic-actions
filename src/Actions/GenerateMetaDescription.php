<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Stringable;
use Throwable;

final class GenerateMetaDescription extends Action
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
        return __('Generate Meta Description');
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
        return 'Generate a meta description for this entry?|Generate meta descriptions for these :count entries?';
    }

    public function buttonText()
    {
        /** @translation */
        return 'Generate Meta Description|Generate Meta Descriptions for :count Entries';
    }

    public function run($items, $values)
    {
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

            $targetFieldHandle = array_key_first($configuredFields);
            if (! is_string($targetFieldHandle) || $targetFieldHandle === '') {
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
                    'extract-meta-description',
                    $item,
                    $targetFieldHandle,
                    ['variables' => ['text' => $sourceText]]
                );

                $queued++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Bulk meta description action failed for entry', [
                    'entry_id' => (string) $item->id(),
                    'field' => $targetFieldHandle,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultMessage($queued, $skipped, $failed);
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
            if (! in_array('extract-meta-description', $actions, true)) {
                continue;
            }

            $configuredFields[(string) $field->handle()] = (string) $field->display();
        }

        $this->configuredFieldCache[$cacheKey] = $configuredFields;

        return $configuredFields;
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

    private function resultMessage(int $queued, int $skipped, int $failed): string
    {
        $messages = [];

        if ($queued > 0) {
            $messages[] = trans_choice(
                'Queued meta description generation for :count entry.|Queued meta description generation for :count entries.',
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
