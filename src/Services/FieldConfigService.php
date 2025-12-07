<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Config;

final class FieldConfigService
{
    private array $config;

    private array $defaultFieldConfig;

    public function __construct()
    {
        $this->config = Config::get('statamic.magic-actions', []);

        $this->defaultFieldConfig = [
            'magic_actions' => [
                'type' => 'section',
                'display' => 'Magic Actions',
            ],
            'magic_actions_enabled' => [
                'type' => 'toggle',
                'display' => 'Enabled',
                'default' => false,
            ],
            'magic_actions_source' => [
                'type' => 'text',
                'display' => 'Source',
                'instructions' => 'The field that contains the content to be processed by Magic Actions.',
                'placeholder' => 'content',
                'sometimes' => ['magic_actions_enabled' => true],
                'if' => ['magic_actions_enabled' => true],
            ],
            'magic_actions_mode' => [
                'type' => 'select',
                'display' => 'Mode',
                'instructions' => 'Whether to append or replace to the existing content.',
                'options' => [
                    'append' => 'Append',
                    'replace' => 'Replace',
                ],
                'default' => 'append',
                'sometimes' => ['magic_actions_enabled' => true],
                'if' => ['magic_actions_enabled' => true],
            ],
        ];
    }

    public function registerFieldConfigs(): void
    {
        foreach ($this->getFieldtypesWithPrompts() as $fieldtype => $settings) {
            if (! empty($settings['actions'])) {
                $config = $this->defaultFieldConfig;
                $config['magic_actions_action'] = [
                    'type' => 'select',
                    'display' => 'Action',
                    'options' => collect($settings['actions'])->pluck('title', 'action')->toArray(),
                    'sometimes' => ['magic_actions_enabled' => true],
                    'if' => ['magic_actions_enabled' => true],
                ];

                $this->appendConfigToFieldtype($fieldtype, $config);
            }
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
                // $actionData is either a FQCN string or already-transformed array with title/action
                $title = null;
                $handle = null;

                if (is_string($actionData) && class_exists($actionData)) {
                    // It's a FQCN string - instantiate and get metadata
                    $action = new $actionData();
                    $title = $action->getTitle();
                    $handle = $action->getHandle();
                } elseif (is_array($actionData) && isset($actionData['title'], $actionData['action'])) {
                    // It's already an array with title/action (from previous transformation)
                    $title = $actionData['title'];
                    $handle = $actionData['action'];
                }

                if ($title && $handle) {
                    $actionsWithPrompts[] = [
                        'title' => $title,
                        'action' => $handle,
                    ];
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

    private function appendConfigToFieldtype(string $fieldtype, array $config): void
    {
        if (! class_exists($fieldtype)) {
            return;
        }

        $fieldtype::appendConfigFields($config);
    }
}
