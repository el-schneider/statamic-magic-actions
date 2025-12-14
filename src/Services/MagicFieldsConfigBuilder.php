<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Statamic\Fields\Blueprint;

use function get_class;

final class MagicFieldsConfigBuilder
{
    public function buildFromBlueprint(?Blueprint $blueprint): ?array
    {
        if (! $blueprint) {
            return null;
        }

        return $blueprint->fields()->all()->filter(function ($field) {
            return $field->config()['magic_actions_enabled'] ?? false;
        })->map(function ($field) {
            $fieldtype = get_class($field->fieldtype());
            $action = $field->config()['magic_actions_action'] ?? null;

            if (! $action) {
                return null;
            }

            // Find the action class by its handle from enabled actions
            $actionClass = null;
            foreach (config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'] ?? [] as $actionData) {
                // Handle both FQCN strings and pre-formatted arrays
                $classPath = null;
                $actionHandle = null;

                if (is_string($actionData) && class_exists($actionData)) {
                    $classPath = $actionData;
                } elseif (is_array($actionData) && isset($actionData['action'])) {
                    $actionHandle = $actionData['action'];
                    $explodedHandle = str_replace('-', ' ', $actionHandle);
                    $className = str_replace(' ', '', ucwords($explodedHandle));
                    $classPath = "ElSchneider\\StatamicMagicActions\\MagicActions\\{$className}";
                }

                if ($classPath && class_exists($classPath)) {
                    $instance = new $classPath();
                    if ($instance->getHandle() === $action) {
                        $actionClass = $instance;
                        break;
                    }
                }
            }

            // Ignore field if action is not enabled in addon config
            if (! $actionClass) {
                return null;
            }

            return [
                'actionHandle' => $action,
                'actionType' => $actionClass->type(),
                'component' => $field->fieldtype()->component(),
                'title' => $actionClass->getTitle(),
                'icon' => $actionClass->icon(),
            ];
        })->filter()->unique('actionHandle')->values()->toArray();
    }
}
