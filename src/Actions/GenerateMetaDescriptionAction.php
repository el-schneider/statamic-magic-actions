<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Throwable;

final class GenerateMetaDescriptionAction extends Action
{
    private const string ACTION_HANDLE = 'extract-meta-description';

    public static function title(): string
    {
        return 'Generate Meta Description';
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
        $jobTracker = app(JobTracker::class);
        $queued = 0;
        $skipped = 0;
        $failed = 0;
        $batchId = null;
        $dispatchPlan = [];

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

            $dispatchPlan[] = [
                'entry' => $item,
                'field' => $fieldHandle,
            ];
        }

        if ($dispatchPlan !== []) {
            $batchId = $jobTracker->createBatch(self::ACTION_HANDLE, count($dispatchPlan), [
                'source' => 'cp_bulk_action',
            ]);
        }

        foreach ($dispatchPlan as $dispatchRow) {
            try {
                $jobId = $executor->execute(
                    self::ACTION_HANDLE,
                    $dispatchRow['entry'],
                    $dispatchRow['field']
                );
                if ($batchId !== null) {
                    $jobTracker->addJobToBatch($batchId, $jobId);
                }
                $queued++;
            } catch (Throwable) {
                $failed++;
            }
        }

        if ($queued === 0) {
            return 'No entries had a Meta Description field configured for magic actions.';
        }

        $messages = [
            trans_choice('Queued :count meta description job.|Queued :count meta description jobs.', $queued),
        ];

        if ($skipped > 0) {
            $messages[] = trans_choice('Skipped :count entry without a configured field.|Skipped :count entries without a configured field.', $skipped);
        }

        if ($failed > 0) {
            $messages[] = trans_choice('Failed to queue :count entry.|Failed to queue :count entries.', $failed);
        }

        if ($queued > 0 && $batchId !== null) {
            $messages[] = "batch_id: {$batchId}";
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
