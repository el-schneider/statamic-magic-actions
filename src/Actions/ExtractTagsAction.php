<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Throwable;

final class ExtractTagsAction extends Action
{
    private const string ACTION_HANDLE = 'extract-tags';

    public static function title(): string
    {
        return 'Extract Tags';
    }

    public function visibleTo($item): bool
    {
        if (! $item instanceof Entry) {
            return false;
        }

        return $this->resolveFieldHandle($item) !== null;
    }

    public function visibleToBulk($items): bool
    {
        return collect($items)->contains(fn ($item): bool => $this->visibleTo($item));
    }

    public function run($items, $values): string
    {
        $executor = app(ActionExecutor::class);
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach (collect($items) as $item) {
            if (! $item instanceof Entry) {
                $skipped++;

                continue;
            }

            $fieldHandle = $this->resolveFieldHandle($item);

            if ($fieldHandle === null) {
                $skipped++;

                continue;
            }

            try {
                $executor->execute(self::ACTION_HANDLE, $item, $fieldHandle);
                $queued++;
            } catch (Throwable) {
                $failed++;
            }
        }

        if ($queued === 0) {
            return 'No entries had an Extract Tags field configured for magic actions.';
        }

        $messages = [
            trans_choice('Queued :count tag extraction job.|Queued :count tag extraction jobs.', $queued),
        ];

        if ($skipped > 0) {
            $messages[] = trans_choice('Skipped :count entry without a configured field.|Skipped :count entries without a configured field.', $skipped);
        }

        if ($failed > 0) {
            $messages[] = trans_choice('Failed to queue :count entry.|Failed to queue :count entries.', $failed);
        }

        return implode(' ', $messages);
    }

    private function resolveFieldHandle(Entry $entry): ?string
    {
        $blueprint = $entry->blueprint();

        if (! $blueprint) {
            return null;
        }

        foreach ($blueprint->fields()->all() as $field) {
            $config = $field->config();
            if (! (bool) ($config['magic_actions_enabled'] ?? false)) {
                continue;
            }

            $configuredActions = $this->normalizeConfiguredActions($config['magic_actions_action'] ?? null);

            if (! in_array(self::ACTION_HANDLE, $configuredActions, true)) {
                continue;
            }

            $handle = $field->handle();

            if (is_string($handle) && $handle !== '') {
                return $handle;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeConfiguredActions(mixed $configuredActions): array
    {
        if (is_string($configuredActions)) {
            $configuredActions = mb_trim($configuredActions);

            return $configuredActions !== '' ? [$configuredActions] : [];
        }

        if (! is_array($configuredActions)) {
            return [];
        }

        $normalized = [];

        foreach ($configuredActions as $action) {
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
}
