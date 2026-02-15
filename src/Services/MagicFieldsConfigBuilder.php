<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Statamic\Fields\Blueprint;

use function get_class;

final class MagicFieldsConfigBuilder
{
    public function __construct(private readonly ActionLoader $actionLoader) {}

    public function buildFromBlueprint(?Blueprint $blueprint): ?array
    {
        if (! $blueprint) {
            return null;
        }

        return $blueprint->fields()->all()->filter(function ($field) {
            return $field->config()['magic_actions_enabled'] ?? false;
        })->map(function ($field) {
            $fieldtype = get_class($field->fieldtype());
            $selectedActions = $this->normalizeSelectedActions($field->config()['magic_actions_action'] ?? null);

            if ($selectedActions === []) {
                return null;
            }

            $enabledActions = $this->resolveEnabledActionsForFieldtype($fieldtype);

            if ($enabledActions === []) {
                return null;
            }

            $fieldActions = collect($selectedActions)
                ->map(fn (string $actionHandle) => $enabledActions[$actionHandle] ?? null)
                ->filter()
                ->values()
                ->toArray();

            if ($fieldActions === []) {
                return null;
            }

            return [
                'fieldHandle' => $field->handle(),
                'component' => $field->fieldtype()->component(),
                'actions' => $fieldActions,
            ];
        })->filter()->values()->toArray();
    }

    private function normalizeSelectedActions(mixed $actionConfig): array
    {
        if (is_string($actionConfig)) {
            $actionConfig = mb_trim($actionConfig);

            return $actionConfig !== '' ? [$actionConfig] : [];
        }

        if (! is_array($actionConfig)) {
            return [];
        }

        $selectedActions = collect($actionConfig)
            ->filter(fn (mixed $actionHandle) => is_string($actionHandle) && mb_trim($actionHandle) !== '')
            ->map(fn (string $actionHandle) => mb_trim($actionHandle))
            ->unique()
            ->values()
            ->toArray();

        return $selectedActions;
    }

    private function resolveEnabledActionsForFieldtype(string $fieldtype): array
    {
        $configuredActions = config("statamic.magic-actions.fieldtypes.{$fieldtype}.actions", []);

        if (! is_array($configuredActions)) {
            return [];
        }

        $resolvedActions = [];

        foreach ($configuredActions as $configuredAction) {
            $action = $this->resolveConfiguredAction($configuredAction);

            if (! $action) {
                continue;
            }

            $actionHandle = $action->getHandle();
            $resolvedActions[$actionHandle] = [
                'title' => $action->getTitle(),
                'actionHandle' => $actionHandle,
                'actionType' => $action->type(),
                'icon' => $action->icon(),
            ];
        }

        return $resolvedActions;
    }

    private function resolveConfiguredAction(mixed $configuredAction): ?MagicAction
    {
        if (is_string($configuredAction) && class_exists($configuredAction)) {
            $instance = new $configuredAction();

            return $instance instanceof MagicAction ? $instance : null;
        }

        if (is_array($configuredAction) && isset($configuredAction['action']) && is_string($configuredAction['action'])) {
            return $this->actionLoader->getMagicAction($configuredAction['action']);
        }

        if (is_string($configuredAction)) {
            return $this->actionLoader->getMagicAction($configuredAction);
        }

        return null;
    }
}
