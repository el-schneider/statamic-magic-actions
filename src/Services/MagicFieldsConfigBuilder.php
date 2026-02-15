<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Statamic\Fields\Fieldtype;
use Throwable;

final class MagicFieldsConfigBuilder
{
    public function __construct(private readonly ActionLoader $actionLoader) {}

    public function buildCatalog(): array
    {
        $fieldtypeConfigs = config('statamic.magic-actions.fieldtypes', []);

        if (! is_array($fieldtypeConfigs)) {
            return [];
        }

        $catalog = [];

        foreach (array_keys($fieldtypeConfigs) as $fieldtype) {
            $component = $this->resolveFieldtypeComponent($fieldtype);
            if (! $component) {
                continue;
            }

            $actions = $this->resolveEnabledActionsForFieldtype($fieldtype);
            if ($actions === []) {
                continue;
            }

            if (! isset($catalog[$component])) {
                $catalog[$component] = [];
            }

            foreach ($actions as $actionHandle => $action) {
                $catalog[$component][$actionHandle] = $action;
            }
        }

        ksort($catalog);

        return collect($catalog)
            ->map(fn (array $actions): array => array_values($actions))
            ->toArray();
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
                'handle' => $actionHandle,
                'actionType' => $action->type(),
                'icon' => $action->icon(),
                'acceptedMimeTypes' => $action->acceptedMimeTypes(),
            ];
        }

        return $resolvedActions;
    }

    private function resolveFieldtypeComponent(mixed $fieldtype): ?string
    {
        if (! is_string($fieldtype) || ! class_exists($fieldtype) || ! is_subclass_of($fieldtype, Fieldtype::class)) {
            return null;
        }

        try {
            $fieldtypeInstance = new $fieldtype();
        } catch (Throwable) {
            return null;
        }

        $component = $fieldtypeInstance->component();

        if (! is_string($component) || mb_trim($component) === '') {
            return null;
        }

        return $component;
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
