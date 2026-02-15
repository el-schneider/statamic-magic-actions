<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Commands;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Illuminate\Console\Command;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Throwable;

final class MagicRunCommand extends Command
{
    protected $signature = 'magic:run
        {--collection= : Target all entries in a collection}
        {--entry= : Target a specific entry by ID}
        {--asset= : Target a specific asset by container::path}
        {--field= : Target field handle (required)}
        {--action= : Override action handle}
        {--overwrite : Overwrite existing values}
        {--queue : Dispatch jobs to queue instead of running synchronously}
        {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Run magic actions against entries or assets from the CLI.';

    public function __construct(
        private readonly ActionExecutor $actionExecutor,
        private readonly ActionLoader $actionLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fieldHandle = $this->stringOption('field');
        $collectionHandle = $this->stringOption('collection');
        $entryId = $this->stringOption('entry');
        $assetIdentifier = $this->stringOption('asset');
        $actionOverride = $this->stringOption('action');

        $overwrite = (bool) $this->option('overwrite');
        $queued = (bool) $this->option('queue');
        $dryRun = (bool) $this->option('dry-run');

        if ($fieldHandle === null) {
            $this->components->error('Missing required option: --field=');

            return self::FAILURE;
        }

        if ($collectionHandle === null && $entryId === null && $assetIdentifier === null) {
            $this->components->error('Provide at least one target via --collection=, --entry=, or --asset=');

            return self::FAILURE;
        }

        if ($actionOverride !== null && ! $this->actionLoader->exists($actionOverride)) {
            $this->components->error("Action '{$actionOverride}' does not exist.");

            return self::FAILURE;
        }

        [$targets, $resolutionErrors] = $this->resolveTargets($collectionHandle, $entryId, $assetIdentifier);
        $totalTargets = count($targets) + count($resolutionErrors);

        if ($totalTargets === 0) {
            $this->components->warn('No targets resolved.');

            return self::FAILURE;
        }

        $statusRows = [];
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $dispatched = 0;

        foreach ($resolutionErrors as $resolutionError) {
            $statusRows[] = [
                'target' => $resolutionError['target'],
                'field' => $fieldHandle,
                'action' => $actionOverride ?? '-',
                'status' => 'failed',
                'message' => $resolutionError['message'],
            ];
            $failed++;
        }

        $executionPlan = [];

        foreach ($targets as $targetRow) {
            $target = $targetRow['target'];
            $targetLabel = $targetRow['label'];

            [$resolvedAction, $actionError] = $this->resolveAction($target, $fieldHandle, $actionOverride);

            if ($resolvedAction === null) {
                $statusRows[] = [
                    'target' => $targetLabel,
                    'field' => $fieldHandle,
                    'action' => '-',
                    'status' => 'failed',
                    'message' => $actionError ?? 'Could not determine action.',
                ];
                $failed++;

                continue;
            }

            if (! $overwrite && $this->fieldHasValue($target, $fieldHandle)) {
                $statusRows[] = [
                    'target' => $targetLabel,
                    'field' => $fieldHandle,
                    'action' => $resolvedAction,
                    'status' => 'skipped',
                    'message' => 'Field already has a value. Use --overwrite to force.',
                ];
                $skipped++;

                continue;
            }

            $options = $this->buildExecutionOptions($target, $fieldHandle);

            if (! $this->actionExecutor->canExecute($resolvedAction, $target, $fieldHandle, $options)) {
                $statusRows[] = [
                    'target' => $targetLabel,
                    'field' => $fieldHandle,
                    'action' => $resolvedAction,
                    'status' => 'failed',
                    'message' => 'Action cannot run for this target/field.',
                ];
                $failed++;

                continue;
            }

            $executionPlan[] = [
                'target' => $target,
                'target_label' => $targetLabel,
                'action' => $resolvedAction,
                'options' => $options,
            ];
        }

        if ($dryRun) {
            $this->components->info('Dry run: no changes will be made.');

            if ($executionPlan === []) {
                $this->components->warn('No targets would be processed.');
            } else {
                $this->components->table(
                    ['Target', 'Field', 'Action'],
                    array_map(
                        fn (array $row): array => [$row['target_label'], $fieldHandle, $row['action']],
                        $executionPlan
                    )
                );
            }

            $processed = count($executionPlan);

            $this->renderStatusTable($statusRows);
            $this->renderSummary($totalTargets, $processed, $skipped, $failed, false, 0);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $progressBar = null;

        if (count($executionPlan) > 1) {
            $progressBar = $this->output->createProgressBar(count($executionPlan));
            $progressBar->start();
        }

        foreach ($executionPlan as $planRow) {
            try {
                if ($queued) {
                    $this->actionExecutor->execute(
                        $planRow['action'],
                        $planRow['target'],
                        $fieldHandle,
                        $planRow['options']
                    );

                    $statusRows[] = [
                        'target' => $planRow['target_label'],
                        'field' => $fieldHandle,
                        'action' => $planRow['action'],
                        'status' => 'queued',
                        'message' => 'Job dispatched.',
                    ];
                    $dispatched++;
                } else {
                    $this->actionExecutor->executeSync(
                        $planRow['action'],
                        $planRow['target'],
                        $fieldHandle,
                        $planRow['options']
                    );

                    $statusRows[] = [
                        'target' => $planRow['target_label'],
                        'field' => $fieldHandle,
                        'action' => $planRow['action'],
                        'status' => 'processed',
                        'message' => 'Completed synchronously.',
                    ];
                }

                $processed++;
            } catch (Throwable $e) {
                $statusRows[] = [
                    'target' => $planRow['target_label'],
                    'field' => $fieldHandle,
                    'action' => $planRow['action'],
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
                $failed++;
            } finally {
                if ($progressBar !== null) {
                    $progressBar->advance();
                }
            }
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        }

        $this->renderStatusTable($statusRows);
        $this->renderSummary($totalTargets, $processed, $skipped, $failed, $queued, $dispatched);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{target: Entry|Asset, label: string}>, 1: array<int, array{target: string, message: string}>}
     */
    private function resolveTargets(?string $collectionHandle, ?string $entryId, ?string $assetIdentifier): array
    {
        $targets = [];
        $errors = [];
        $seen = [];

        if ($collectionHandle !== null) {
            $collection = CollectionFacade::findByHandle($collectionHandle);

            if (! $collection) {
                $errors[] = [
                    'target' => "collection:{$collectionHandle}",
                    'message' => 'Collection not found.',
                ];
            } else {
                foreach ($collection->queryEntries()->get() as $entry) {
                    if (! $entry instanceof Entry) {
                        continue;
                    }

                    $key = $this->targetKey($entry);
                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $targets[] = [
                        'target' => $entry,
                        'label' => $this->describeTarget($entry),
                    ];
                }
            }
        }

        if ($entryId !== null) {
            $entry = EntryFacade::find($entryId);

            if (! $entry) {
                $errors[] = [
                    'target' => "entry:{$entryId}",
                    'message' => 'Entry not found.',
                ];
            } else {
                $key = $this->targetKey($entry);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $targets[] = [
                        'target' => $entry,
                        'label' => $this->describeTarget($entry),
                    ];
                }
            }
        }

        if ($assetIdentifier !== null) {
            $asset = $this->resolveAsset($assetIdentifier);

            if (! $asset) {
                $errors[] = [
                    'target' => "asset:{$assetIdentifier}",
                    'message' => 'Asset not found. Expected format: container::path',
                ];
            } else {
                $key = $this->targetKey($asset);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $targets[] = [
                        'target' => $asset,
                        'label' => $this->describeTarget($asset),
                    ];
                }
            }
        }

        return [$targets, $errors];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAction(Entry|Asset $target, string $fieldHandle, ?string $actionOverride): array
    {
        if ($actionOverride !== null) {
            return [$actionOverride, null];
        }

        $blueprint = $target->blueprint();

        if (! $blueprint) {
            return [null, 'Target has no blueprint; unable to resolve field action config.'];
        }

        $field = $blueprint->field($fieldHandle);

        if (! $field) {
            return [null, "Field '{$fieldHandle}' does not exist on target blueprint."];
        }

        $configuredActions = $this->normalizeConfiguredActions($field->config()['magic_actions_action'] ?? null);

        if ($configuredActions === []) {
            return [null, "Field '{$fieldHandle}' has no configured magic_actions_action."];
        }

        if (count($configuredActions) > 1) {
            return [
                null,
                "Field '{$fieldHandle}' has multiple configured actions. Use --action= to select one.",
            ];
        }

        $action = $configuredActions[0];

        if (! $this->actionLoader->exists($action)) {
            return [null, "Configured action '{$action}' does not exist."];
        }

        return [$action, null];
    }

    private function fieldHasValue(Entry|Asset $target, string $fieldHandle): bool
    {
        $value = $target->get($fieldHandle);

        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return mb_trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @return array{asset_path?: string}
     */
    private function buildExecutionOptions(Entry|Asset $target, string $fieldHandle): array
    {
        if (! $target instanceof Entry) {
            return [];
        }

        $blueprint = $target->blueprint();
        $field = $blueprint?->field($fieldHandle);

        if (! $field) {
            return [];
        }

        $sourceHandle = $field->config()['magic_actions_source'] ?? null;

        if (! is_string($sourceHandle) || $sourceHandle === '') {
            return [];
        }

        $assetPath = $this->extractAssetPath($target->get($sourceHandle));

        return $assetPath !== null ? ['asset_path' => $assetPath] : [];
    }

    private function extractAssetPath(mixed $value): ?string
    {
        if ($value instanceof Asset) {
            return (string) $value->id();
        }

        if (is_string($value)) {
            $value = mb_trim($value);

            return $value !== '' ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $resolved = $this->extractAssetPath($item);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
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

    private function targetKey(Entry|Asset $target): string
    {
        $type = $target instanceof Entry ? 'entry' : 'asset';

        return "{$type}:".(string) $target->id();
    }

    private function describeTarget(Entry|Asset $target): string
    {
        if ($target instanceof Entry) {
            $collectionHandle = (string) $target->collectionHandle();
            $site = (string) $target->locale();

            return "entry:{$target->id()} [collection={$collectionHandle}, site={$site}]";
        }

        return 'asset:'.(string) $target->id();
    }

    private function resolveAsset(string $identifier): ?Asset
    {
        $asset = AssetFacade::find($identifier);

        if ($asset) {
            return $asset;
        }

        if (! str_contains($identifier, '/') || str_contains($identifier, '::')) {
            return null;
        }

        [$container, $path] = explode('/', $identifier, 2);

        if ($path === '') {
            return null;
        }

        return AssetFacade::find("{$container}::{$path}");
    }

    private function renderStatusTable(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->components->table(
            ['Target', 'Field', 'Action', 'Status', 'Message'],
            array_map(
                fn (array $row): array => [$row['target'], $row['field'], $row['action'], $row['status'], $row['message']],
                $rows
            )
        );
    }

    private function renderSummary(
        int $totalTargets,
        int $processed,
        int $skipped,
        int $failed,
        bool $queued,
        int $dispatched
    ): void {
        $summaryRows = [
            ['Total targets', (string) $totalTargets],
            ['Processed', (string) $processed],
            ['Skipped', (string) $skipped],
            ['Failed', (string) $failed],
        ];

        if ($queued) {
            $summaryRows[] = ['Dispatched jobs', (string) $dispatched];
        }

        $this->components->table(['Metric', 'Count'], $summaryRows);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value !== '' ? $value : null;
    }
}
