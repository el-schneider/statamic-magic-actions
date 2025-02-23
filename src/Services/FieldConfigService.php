<?php

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Config;
use Statamic\Facades\File;
use Statamic\Fields\Field;

class FieldConfigService
{
    protected array $config;
    protected array $defaultFieldConfig;

    public function __construct()
    {
        $this->config = Config::get('statamic.magic-actions', []);

        $this->defaultFieldConfig = [
            'magic_tags' => [
                'type' => 'section',
                'display' => 'Magic Tags',
            ],
            'magic_tags_enabled' => [
                'type' => 'toggle',
                'display' => 'Magic Tags Enabled',
                'default' => false,
            ],
            'magic_tags_source' => [
                'type' => 'text',
                'display' => 'Magic Tags Source',
                'instructions' => 'The field that contains the content to be processed by Magic Tags.',
                'placeholder' => 'content',
                'required' => ['magic_tags_enabled' => true],
                'if' => ['magic_tags_enabled' => true],
            ],
        ];
    }


    public function registerFieldConfigs(): void
    {
        foreach ($this->getFieldtypesWithPrompts() as $fieldtype => $settings) {
            if (!empty($settings['actions'])) {
                $config = $this->defaultFieldConfig;
                $config['magic_tags_action'] = [
                    'type' => 'select',
                    'display' => 'Magic Tags Action',
                    'options' => collect($settings['actions'])->pluck('title', 'handle')->toArray(),
                    'required' => ['magic_tags_enabled' => true],
                    'if' => ['magic_tags_enabled' => true],
                ];

                $this->appendConfigToFieldtype($fieldtype, $config);
            }
        }
    }

    protected function appendConfigToFieldtype(string $fieldtype, array $config): void
    {
        if (!class_exists($fieldtype)) {
            return;
        }

        $fieldtype::appendConfigFields($config);
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

            foreach ($settings['actions'] ?? [] as $action) {
                $publishedPromptPath = resource_path('prompts/' . $action['handle'] . '.md');
                $addonPromptPath = __DIR__ . '/../../resources/prompts/' . $action['handle'] . '.md';

                if (File::exists($publishedPromptPath) || File::exists($addonPromptPath)) {
                    $actionsWithPrompts[] = $action;
                }
            }

            if (!empty($actionsWithPrompts)) {
                $fieldtypesWithPrompts[$fieldtype] = [
                    'actions' => $actionsWithPrompts
                ];
            }
        }

        return $fieldtypesWithPrompts;
    }
}
