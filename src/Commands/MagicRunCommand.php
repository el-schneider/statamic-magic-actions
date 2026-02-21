<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Commands;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Throwable;

final class MagicRunCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:magic:run';

    protected $description = '';

    public function __construct(
        private readonly ActionExecutor $actionExecutor,
        private readonly ActionLoader $actionLoader,
        private readonly JobTracker $jobTracker,
    ) {
        $this->signature = $this->buildSignature();
        $this->description = $this->t('cli.description');

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
            $this->components->error($this->t('cli.errors.overwrite_conflict'));

            return self::FAILURE;
        }

        if ($this->booleanOptionProvided('queue') && $this->booleanOptionProvided('no-queue')) {
            $this->components->error($this->t('cli.errors.queue_conflict'));

            return self::FAILURE;
        }

        $overwrite = $this->resolveBooleanOption('overwrite', 'no-overwrite', 'statamic.magic-actions.cli.overwrite', false);
        $queued = $this->resolveBooleanOption('queue', 'no-queue', 'statamic.magic-actions.cli.queue', true);
        $dryRun = (bool) $this->option('dry-run');

        if ($fieldHandle === null) {
            $this->components->error($this->t('cli.errors.missing_field'));

            return self::FAILURE;
        }

        if ($collectionHandle === null && $entryId === null && $assetIdentifier === null) {
            $this->components->error($this->t('cli.errors.missing_target'));

            return self::FAILURE;
        }

        if ($actionOverride !== null && ! $this->actionLoader->exists($actionOverride)) {
            $availableActions = $this->formatList($this->availableActionHandles());
            $this->components->error($this->t('cli.errors.action_not_found', [
                'action' => $actionOverride,
                'actions' => $availableActions,
            ]));

            return self::FAILURE;
        }

        [$targets, $resolutionErrors] = $this->resolveTargets($collectionHandle, $entryId, $assetIdentifier);
        $totalTargets = count($targets) + count($resolutionErrors);

        if ($totalTargets === 0) {
            $this->components->warn($this->t('cli.errors.no_targets_resolved'));

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
                'status' => $this->t('cli.status.failed'),
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
                    'status' => $this->t('cli.status.failed'),
                    'message' => $actionError ?? $this->t('cli.errors.could_not_determine_action'),
                ];
                $failed++;

                continue;
            }

            if (! $overwrite && $this->fieldHasValue($target, $fieldHandle)) {
                $statusRows[] = [
                    'target' => $targetLabel,
                    'field' => $fieldHandle,
                    'action' => $resolvedAction,
                    'status' => $this->t('cli.status.skipped'),
                    'message' => $this->t('cli.errors.field_has_value'),
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
                    'status' => $this->t('cli.status.failed'),
                    'message' => $this->t('cli.errors.cannot_run_for_target'),
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
            $this->components->info($this->t('cli.messages.dry_run'));

            if ($executionPlan === []) {
                $this->components->warn($this->t('cli.messages.dry_run_no_targets'));
            } else {
                $this->table(
                    [
                        $this->t('cli.table.execution_headers.target'),
                        $this->t('cli.table.execution_headers.field'),
                        $this->t('cli.table.execution_headers.action'),
                    ],
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
                        'status' => $this->t('cli.status.queued'),
                        'message' => $this->t('cli.messages.job_dispatched'),
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
                        'status' => $this->t('cli.status.processed'),
                        'message' => $this->t('cli.messages.completed_sync'),
                    ];
                }

                $processed++;
            } catch (Throwable $e) {
                $statusRows[] = [
                    'target' => $planRow['target_label'],
                    'field' => $fieldHandle,
                    'action' => $planRow['action'],
                    'status' => $this->t('cli.status.failed'),
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
                    'message' => $this->t('cli.errors.collection_not_found', [
                        'collection' => $collectionHandle,
                        'collections' => $availableCollections,
                    ]),
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
                    'message' => $this->t('cli.errors.entry_not_found'),
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
                    'message' => $this->t('cli.errors.asset_not_found'),
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
            return [null, $this->t('cli.errors.target_has_no_blueprint')];
        }

        $field = $blueprint->field($fieldHandle);

        if (! $field) {
            return [null, $this->t('cli.errors.field_missing_on_blueprint', ['field' => $fieldHandle])];
        }

        $configuredActions = $this->normalizeConfiguredActions($field->config()['magic_actions_action'] ?? null);

        if ($configuredActions === []) {
            return [null, $this->t('cli.errors.field_no_configured_actions', ['field' => $fieldHandle])];
        }

        if (count($configuredActions) > 1) {
            return [
                null,
                $this->t('cli.errors.field_multiple_actions', ['field' => $fieldHandle]),
            ];
        }

        $action = $configuredActions[0];

        if (! $this->actionLoader->exists($action)) {
            $availableActions = $this->formatList($this->availableActionHandles());

            return [null, $this->t('cli.errors.configured_action_missing', [
                'action' => $action,
                'actions' => $availableActions,
            ])];
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

            return $this->t('cli.targets.entry_label', [
                'id' => (string) $target->id(),
                'collection' => $collectionHandle,
                'site' => $site,
            ]);
        }

        return $this->t('cli.targets.asset_label', ['id' => (string) $target->id()]);
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
            [
                $this->t('cli.table.status_headers.target'),
                $this->t('cli.table.status_headers.field'),
                $this->t('cli.table.status_headers.action'),
                $this->t('cli.table.status_headers.status'),
                $this->t('cli.table.status_headers.message'),
            ],
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
            [$this->t('cli.table.summary.total_targets'), (string) $totalTargets],
            [$this->t('cli.table.summary.processed'), (string) $processed],
            [$this->t('cli.table.summary.skipped'), (string) $skipped],
            [$this->t('cli.table.summary.failed'), (string) $failed],
        ];

        if ($queued) {
            $summaryRows[] = [$this->t('cli.table.summary.dispatched_jobs'), (string) $dispatched];

            if ($batchId !== null) {
                $summaryRows[] = [$this->t('cli.table.summary.batch_id'), $batchId];
            }
        }

        $this->table([
            $this->t('cli.table.summary_headers.metric'),
            $this->t('cli.table.summary_headers.count'),
        ], $summaryRows);
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

        return $this->t('cli.misc.mixed');
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
        return $values === [] ? $this->t('cli.misc.none') : implode(', ', $values);
    }

    private function buildSignature(): string
    {
        return implode("\n", [
            'statamic:magic:run',
            '    {--collection= : '.$this->t('cli.options.collection').'}',
            '    {--entry= : '.$this->t('cli.options.entry').'}',
            '    {--asset= : '.$this->t('cli.options.asset').'}',
            '    {--field= : '.$this->t('cli.options.field').'}',
            '    {--action= : '.$this->t('cli.options.action').'}',
            '    {--overwrite : '.$this->t('cli.options.overwrite').'}',
            '    {--no-overwrite : '.$this->t('cli.options.no_overwrite').'}',
            '    {--queue : '.$this->t('cli.options.queue').'}',
            '    {--no-queue : '.$this->t('cli.options.no_queue').'}',
            '    {--dry-run : '.$this->t('cli.options.dry_run').'}',
        ]);
    }

    private function t(string $key, array $replace = []): string
    {
        return __('magic-actions::magic-actions.'.$key, $replace);
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
