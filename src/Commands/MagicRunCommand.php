<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Commands;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
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
        {--no-overwrite : Disable overwrite even when enabled by config}
        {--queue : Dispatch jobs to queue instead of running synchronously}
        {--no-queue : Run synchronously even when queueing is enabled by config}
        {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Run magic actions against entries or assets from the CLI.';

    public function __construct(
        private readonly ActionExecutor $actionExecutor,
        private readonly ActionLoader $actionLoader,
        private readonly JobTracker $jobTracker,
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

        if ($this->booleanOptionProvided('overwrite') && $this->booleanOptionProvided('no-overwrite')) {
            $this->components->error('Use either --overwrite or --no-overwrite, not both.');

            return self::FAILURE;
        }

        if ($this->booleanOptionProvided('queue') && $this->booleanOptionProvided('no-queue')) {
            $this->components->error('Use either --queue or --no-queue, not both.');

            return self::FAILURE;
        }

        $overwrite = $this->resolveBooleanOption('overwrite', 'no-overwrite', 'statamic.magic-actions.cli.overwrite', false);
        $queued = $this->resolveBooleanOption('queue', 'no-queue', 'statamic.magic-actions.cli.queue', true);
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
            $availableActions = $this->formatList($this->availableActionHandles());
            $this->components->error(
                "Action '{$actionOverride}' not found. Available actions: {$availableActions}. ".
                'Check config/statamic/magic-actions.php.'
            );

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
                $this->table(
                    ['Target', 'Field', 'Action'],
                    array_map(
                        fn (array $row): array => [$row['target_label'], $fieldHandle, $row['action']],
                        $executionPlan
                    )
                );
            }

            $processed = count($executionPlan);

            $this->renderStatusTable($statusRows);
            $this->renderSummary($totalTargets, $processed, $skipped, $failed, false, 0, null);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $batchId = null;

        if ($queued && count($executionPlan) > 1) {
            $batchId = $this->jobTracker->createBatch(
                $this->resolveBatchAction($executionPlan),
                count($executionPlan),
                ['source' => 'cli_magic_run']
            );
        }

        $progressBar = null;

        if (count($executionPlan) > 1) {
            $progressBar = $this->output->createProgressBar(count($executionPlan));
            $progressBar->start();
        }

        foreach ($executionPlan as $planRow) {
            try {
                if ($queued) {
                    $jobId = $this->actionExecutor->execute(
                        $planRow['action'],
                        $planRow['target'],
                        $fieldHandle,
                        $planRow['options']
                    );

                    if ($batchId !== null) {
                        $this->jobTracker->addJobToBatch($batchId, $jobId);
                    }

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
        $this->renderSummary($totalTargets, $processed, $skipped, $failed, $queued, $dispatched, $batchId);

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
                $availableCollections = $this->formatList($this->availableCollectionHandles());
                $errors[] = [
                    'target' => "collection:{$collectionHandle}",
                    'message' => "Collection '{$collectionHandle}' not found. Available collections: {$availableCollections}.",
                ];
            } else {
                foreach ($collection->queryEntries()->get() as $entry) {
                    if (! $entry instanceof Entry) {
                        continue;
                    }

                    $this->addTargetIfNotSeen($targets, $seen, $entry);
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
                $this->addTargetIfNotSeen($targets, $seen, $entry);
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
                $this->addTargetIfNotSeen($targets, $seen, $asset);
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
            $availableActions = $this->formatList($this->availableActionHandles());

            return [null, "Configured action '{$action}' does not exist. Available actions: {$availableActions}."];
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

        $this->table(
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
        int $dispatched,
        ?string $batchId
    ): void {
        $summaryRows = [
            ['Total targets', (string) $totalTargets],
            ['Processed', (string) $processed],
            ['Skipped', (string) $skipped],
            ['Failed', (string) $failed],
        ];

        if ($queued) {
            $summaryRows[] = ['Dispatched jobs', (string) $dispatched];

            if ($batchId !== null) {
                $summaryRows[] = ['Batch ID', $batchId];
            }
        }

        $this->table(['Metric', 'Count'], $summaryRows);
    }

    /**
     * @param  array<int, array{target: Entry|Asset, target_label: string, action: string, options: array<string, mixed>}>  $executionPlan
     */
    private function resolveBatchAction(array $executionPlan): string
    {
        $actions = array_values(array_unique(array_map(
            static fn (array $row): string => $row['action'],
            $executionPlan
        )));

        if (count($actions) === 1) {
            return $actions[0];
        }

        return 'mixed';
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

    private function resolveBooleanOption(
        string $option,
        string $negatedOption,
        string $configKey,
        bool $fallback
    ): bool {
        if ($this->booleanOptionProvided($negatedOption)) {
            return false;
        }

        if ($this->booleanOptionProvided($option)) {
            return (bool) $this->option($option);
        }

        return (bool) config($configKey, $fallback);
    }

    private function booleanOptionProvided(string $option): bool
    {
        return $this->input->hasParameterOption("--{$option}", true);
    }

    /**
     * @return array<int, string>
     */
    private function availableCollectionHandles(): array
    {
        $collectionHandles = CollectionFacade::handles()->all();
        $normalized = [];

        foreach ($collectionHandles as $handle) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $normalized[$handle] = $handle;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function availableActionHandles(): array
    {
        $fieldtypes = config('statamic.magic-actions.fieldtypes', []);

        if (! is_array($fieldtypes)) {
            return [];
        }

        $handles = [];

        foreach ($fieldtypes as $fieldtypeConfig) {
            if (! is_array($fieldtypeConfig)) {
                continue;
            }

            $configuredActions = $fieldtypeConfig['actions'] ?? [];

            if (! is_array($configuredActions)) {
                continue;
            }

            foreach ($configuredActions as $configuredAction) {
                $handle = $this->resolveConfiguredActionHandle($configuredAction);

                if ($handle === null || ! $this->actionLoader->exists($handle)) {
                    continue;
                }

                $handles[$handle] = $handle;
            }
        }

        ksort($handles);

        return array_values($handles);
    }

    private function resolveConfiguredActionHandle(mixed $configuredAction): ?string
    {
        if (is_array($configuredAction) && isset($configuredAction['action']) && is_string($configuredAction['action'])) {
            $action = mb_trim($configuredAction['action']);

            return $action !== '' ? $action : null;
        }

        if (! is_string($configuredAction)) {
            return null;
        }

        $configuredAction = mb_trim($configuredAction);

        if ($configuredAction === '') {
            return null;
        }

        if ($this->actionLoader->exists($configuredAction)) {
            return $configuredAction;
        }

        if (! is_subclass_of($configuredAction, MagicAction::class)) {
            return null;
        }

        return ActionRegistry::classNameToHandle($configuredAction);
    }

    private function formatList(array $values): string
    {
        return $values === [] ? '(none)' : implode(', ', $values);
    }

    /**
     * @param  array<int, array{target: Entry|Asset, label: string}>  $targets
     * @param  array<string, bool>  $seen
     */
    private function addTargetIfNotSeen(array &$targets, array &$seen, Entry|Asset $target): void
    {
        $key = $this->targetKey($target);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $targets[] = [
            'target' => $target,
            'label' => $this->describeTarget($target),
        ];
    }
}
