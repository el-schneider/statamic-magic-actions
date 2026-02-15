<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Illuminate\Support\Facades\Config;

final class FieldConfigService
{
    private const array DEFAULT_FIELD_CONFIG = [
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

            $config = self::DEFAULT_FIELD_CONFIG;
            $config['magic_actions_action'] = [
                'type' => 'select',
                'display' => 'Actions',
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
