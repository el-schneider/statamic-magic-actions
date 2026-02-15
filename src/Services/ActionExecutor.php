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
use Statamic\Facades\Asset as AssetFacade;

final class ActionExecutor
{
    public function __construct(
        private readonly ActionLoader $actionLoader,
        private readonly JobTracker $jobTracker,
    ) {}

    public function execute(string $action, Entry|Asset $target, string $fieldHandle, array $options = []): string
    {
        $this->assertMimeTypeSupported($action, $target, $options);

        if (! $this->canExecute($action, $target, $fieldHandle, $options)) {
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
        $this->assertMimeTypeSupported($action, $target, $options);

        if (! $this->canExecute($action, $target, $fieldHandle, $options)) {
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

    public function canExecute(string $action, Entry|Asset $target, string $fieldHandle, array $options = []): bool
    {
        if (! $this->actionLoader->exists($action)) {
            return false;
        }

        if (! in_array($action, $this->getAvailableActions($target, $fieldHandle), true)) {
            return false;
        }

        return ! $this->isMimeTypeUnsupported($action, $target, $options);
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
        if (array_key_exists('asset_path', $options)) {
            $assetPath = $options['asset_path'];

            if (is_string($assetPath) && $assetPath !== '') {
                return $assetPath;
            }

            return null;
        }

        return $target instanceof Asset ? (string) $target->id() : null;
    }

    private function assertMimeTypeSupported(string $action, Entry|Asset $target, array $options): void
    {
        $magicAction = $this->actionLoader->getMagicAction($action);
        if (! $magicAction) {
            return;
        }

        $acceptedMimeTypes = $this->normalizedAcceptedMimeTypes($magicAction);
        if ($acceptedMimeTypes === []) {
            return;
        }

        $asset = $this->resolveAssetForMimeValidation($target, $options);
        if (! $asset) {
            return;
        }

        $assetMimeType = Str::lower(mb_trim((string) $asset->mimeType()));

        if (! $this->mimeTypeMatches($assetMimeType, $acceptedMimeTypes)) {
            $accepted = implode(', ', $acceptedMimeTypes);
            $actionName = class_basename($magicAction);
            $displayMimeType = $assetMimeType !== '' ? $assetMimeType : 'unknown';
            throw new InvalidArgumentException(
                "Action {$actionName} does not support file type {$displayMimeType}. Accepted types: {$accepted}"
            );
        }
    }

    private function isMimeTypeUnsupported(string $action, Entry|Asset $target, array $options): bool
    {
        $magicAction = $this->actionLoader->getMagicAction($action);
        if (! $magicAction) {
            return false;
        }

        $acceptedMimeTypes = $this->normalizedAcceptedMimeTypes($magicAction);
        if ($acceptedMimeTypes === []) {
            return false;
        }

        $asset = $this->resolveAssetForMimeValidation($target, $options);
        if (! $asset) {
            return false;
        }

        $assetMimeType = Str::lower(mb_trim((string) $asset->mimeType()));

        return ! $this->mimeTypeMatches($assetMimeType, $acceptedMimeTypes);
    }

    private function normalizedAcceptedMimeTypes(MagicAction $action): array
    {
        $acceptedMimeTypes = [];

        foreach ($action->acceptedMimeTypes() as $mimeType) {
            if (! is_string($mimeType)) {
                continue;
            }

            $normalizedMimeType = Str::lower(mb_trim($mimeType));

            if ($normalizedMimeType === '') {
                continue;
            }

            $acceptedMimeTypes[] = $normalizedMimeType;
        }

        return array_values(array_unique($acceptedMimeTypes));
    }

    private function resolveAssetForMimeValidation(Entry|Asset $target, array $options): ?Asset
    {
        if ($target instanceof Asset) {
            return $target;
        }

        $assetPath = $options['asset_path'] ?? null;

        if (! is_string($assetPath) || $assetPath === '') {
            return null;
        }

        return $this->findAsset($assetPath);
    }

    private function findAsset(string $identifier): ?Asset
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

    private function mimeTypeMatches(string $assetMimeType, array $acceptedMimeTypes): bool
    {
        if ($assetMimeType === '') {
            return false;
        }

        foreach ($acceptedMimeTypes as $pattern) {
            if ($pattern === '*/*' || $pattern === '*') {
                return true;
            }

            if (! str_contains($pattern, '*')) {
                if ($assetMimeType === $pattern) {
                    return true;
                }

                continue;
            }

            $patternRegex = '/^'.str_replace('\*', '[^\/]+', preg_quote($pattern, '/')).'$/';
            if (preg_match($patternRegex, $assetMimeType) === 1) {
                return true;
            }
        }

        return false;
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

        return (new $configuredAction)->getHandle();
    }
}
