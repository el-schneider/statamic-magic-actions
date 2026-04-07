<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Illuminate\Support\Facades\Config;

final class FieldConfigService
{
    private readonly array $config;

    public function __construct()
    {
        $this->config = Config::get('statamic.magic-actions', []);
    }

    public function registerFieldConfigs(): void
    {
        foreach ($this->getFieldtypesWithPrompts() as $fieldtype => $settings) {
            if (empty($settings['actions'])) {
                continue;
            }

            $config = self::defaultFieldConfig();
            $config['magic_actions_action'] = [
                'type' => 'select',
                'display' => __('magic-actions::magic-actions.field_config.actions'),
                'multiple' => true,
                'options' => collect($settings['actions'])->pluck('title', 'action')->toArray(),
                'sometimes' => ['magic_actions_enabled' => true],
                'if' => ['magic_actions_enabled' => true],
            ];

            $this->appendConfigToFieldtype($fieldtype, $config);
        }
    }

    public function getFieldConfig(): array
    {
        return $this->config['field_config'] ?? [];
    }

    public function getFieldtypesWithPrompts(): array
    {
        $fieldtypesWithPrompts = [];

        foreach ($this->config['fieldtypes'] ?? [] as $fieldtype => $settings) {
            $actionsWithPrompts = [];

            foreach ($settings['actions'] ?? [] as $actionData) {
                $resolvedAction = $this->resolveActionData($actionData);

                if ($resolvedAction !== null) {
                    $actionsWithPrompts[] = $resolvedAction;
                }
            }

            if (! empty($actionsWithPrompts)) {
                $fieldtypesWithPrompts[$fieldtype] = [
                    'actions' => $actionsWithPrompts,
                ];
            }
        }

        return $fieldtypesWithPrompts;
    }

    private static function defaultFieldConfig(): array
    {
        return [
            'magic_actions' => [
                'type' => 'section',
                'display' => __('magic-actions::magic-actions.field_config.section'),
            ],
            'magic_actions_enabled' => [
                'type' => 'toggle',
                'display' => __('magic-actions::magic-actions.field_config.enabled'),
                'default' => false,
            ],
            'magic_actions_source' => [
                'type' => 'text',
                'display' => __('magic-actions::magic-actions.field_config.source'),
                'instructions' => __('magic-actions::magic-actions.field_config.source_instructions'),
                'placeholder' => __('magic-actions::magic-actions.field_config.source_placeholder'),
                'sometimes' => ['magic_actions_enabled' => true],
                'if' => ['magic_actions_enabled' => true],
            ],
            'magic_actions_mode' => [
                'type' => 'select',
                'display' => __('magic-actions::magic-actions.field_config.mode'),
                'instructions' => __('magic-actions::magic-actions.field_config.mode_instructions'),
                'options' => [
                    'append' => __('magic-actions::magic-actions.field_config.mode_append'),
                    'replace' => __('magic-actions::magic-actions.field_config.mode_replace'),
                ],
                'default' => 'append',
                'sometimes' => ['magic_actions_enabled' => true],
                'if' => ['magic_actions_enabled' => true],
            ],
        ];
    }

    private function appendConfigToFieldtype(string $fieldtype, array $config): void
    {
        if (! class_exists($fieldtype)) {
            return;
        }

        $fieldtype::appendConfigFields($config);
    }

    /**
     * @return array{title: string, action: string}|null
     */
    private function resolveActionData(mixed $actionData): ?array
    {
        if (is_array($actionData) && isset($actionData['title'], $actionData['action'])) {
            $title = mb_trim((string) $actionData['title']);
            $action = mb_trim((string) $actionData['action']);

            if ($title === '' || $action === '') {
                return null;
            }

            return [
                'title' => $title,
                'action' => $action,
            ];
        }

        if (! is_string($actionData) || ! class_exists($actionData)) {
            return null;
        }

        $action = new $actionData();

        if (! $action instanceof MagicAction) {
            return null;
        }

        $title = mb_trim($action->getTitle());
        $handle = mb_trim($action->getHandle());

        if ($title === '' || $handle === '') {
            return null;
        }

        return [
            'title' => $title,
            'action' => $handle,
        ];
    }
}
