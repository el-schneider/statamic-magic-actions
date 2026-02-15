<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;

final class ActionExecutor
{
    public function __construct(
        private readonly ActionLoader $actionLoader,
        private readonly JobTracker $jobTracker,
    ) {}

    public function execute(string $action, Entry|Asset $target, string $fieldHandle, array $options = []): string
    {
        if (! $this->canExecute($action, $target, $fieldHandle)) {
            throw new InvalidArgumentException("Action '{$action}' cannot be executed for field '{$fieldHandle}'.");
        }

        $jobId = (string) Str::uuid();
        $context = $this->buildContext($target, $fieldHandle);

        $this->jobTracker->createJob(
            $jobId,
            $action,
            $context['type'],
            $context['id'],
            $context['field']
        );

        ProcessPromptJob::dispatch(
            $jobId,
            $action,
            $this->resolveVariables($options),
            $this->resolveAssetPath($target, $options),
            $context
        );

        return $jobId;
    }

    public function executeSync(string $action, Entry|Asset $target, string $fieldHandle, array $options = []): mixed
    {
        if (! $this->canExecute($action, $target, $fieldHandle)) {
            throw new InvalidArgumentException("Action '{$action}' cannot be executed for field '{$fieldHandle}'.");
        }

        $jobId = (string) Str::uuid();
        $context = $this->buildContext($target, $fieldHandle);

        $this->jobTracker->createJob(
            $jobId,
            $action,
            $context['type'],
            $context['id'],
            $context['field']
        );

        ProcessPromptJob::dispatchSync(
            $jobId,
            $action,
            $this->resolveVariables($options),
            $this->resolveAssetPath($target, $options),
            $context
        );

        $job = $this->jobTracker->getJob($jobId);

        if (($job['status'] ?? null) === 'failed') {
            throw new RuntimeException((string) ($job['message'] ?? 'Action execution failed.'));
        }

        return $job['data'] ?? null;
    }

    public function canExecute(string $action, Entry|Asset $target, string $fieldHandle): bool
    {
        if (! $this->actionLoader->exists($action)) {
            return false;
        }

        return in_array($action, $this->getAvailableActions($target, $fieldHandle), true);
    }

    public function getAvailableActions(Entry|Asset $target, string $fieldHandle): array
    {
        $field = $this->resolveField($target, $fieldHandle);

        if (! $field) {
            return [];
        }

        $fieldtype = get_class($field->fieldtype());
        $configuredActions = Config::get("statamic.magic-actions.fieldtypes.{$fieldtype}.actions", []);

        if (! is_array($configuredActions)) {
            return [];
        }

        $handles = [];

        foreach ($configuredActions as $configuredAction) {
            $handle = $this->resolveActionHandle($configuredAction);
            if ($handle !== null && $this->actionLoader->exists($handle)) {
                $handles[] = $handle;
            }
        }

        return array_values(array_unique($handles));
    }

    private function buildContext(Entry|Asset $target, string $fieldHandle): array
    {
        return [
            'type' => $target instanceof Entry ? 'entry' : 'asset',
            'id' => (string) $target->id(),
            'field' => $fieldHandle,
        ];
    }

    private function resolveVariables(array $options): array
    {
        $variables = $options['variables'] ?? [];

        return is_array($variables) ? $variables : [];
    }

    private function resolveAssetPath(Entry|Asset $target, array $options): ?string
    {
        $assetPath = $options['asset_path'] ?? null;

        if (is_string($assetPath) && $assetPath !== '') {
            return $assetPath;
        }

        return $target instanceof Asset ? (string) $target->id() : null;
    }

    private function resolveField(Entry|Asset $target, string $fieldHandle): mixed
    {
        $blueprint = $target->blueprint();

        if (! $blueprint) {
            return null;
        }

        return $blueprint->field($fieldHandle);
    }

    private function resolveActionHandle(mixed $configuredAction): ?string
    {
        if (is_array($configuredAction) && isset($configuredAction['action']) && is_string($configuredAction['action'])) {
            return $configuredAction['action'];
        }

        if (! is_string($configuredAction)) {
            return null;
        }

        if ($this->actionLoader->exists($configuredAction)) {
            return $configuredAction;
        }

        if (! class_exists($configuredAction) || ! is_subclass_of($configuredAction, MagicAction::class)) {
            return null;
        }

        return (new $configuredAction())->getHandle();
    }
}
