<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Settings;

use Illuminate\Support\Facades\Config;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint as StatamicBlueprint;

final class Blueprint
{
    /**
     * Transform flat form values to nested settings structure
     */
    public static function valuesToSettings(array $values): array
    {
        $settings = [
            'global' => [
                'system_prompt' => $values['global_system_prompt'] ?? null,
                'defaults' => [],
            ],
        ];

        foreach ($values as $key => $value) {
            if (! str_starts_with($key, 'global_defaults_') || empty($value)) {
                continue;
            }

            $capability = str_replace('global_defaults_', '', $key);
            $settings['global']['defaults'][$capability] = $value;
        }

        if (empty($settings['global']['system_prompt'])) {
            unset($settings['global']['system_prompt']);
        }
        if (empty($settings['global']['defaults'])) {
            unset($settings['global']['defaults']);
        }
        if (empty($settings['global'])) {
            unset($settings['global']);
        }

        return $settings;
    }

    /**
     * Transform nested settings structure to flat form values
     */
    public static function settingsToValues(array $settings): array
    {
        $values = [];

        if (isset($settings['global']['system_prompt'])) {
            $values['global_system_prompt'] = $settings['global']['system_prompt'];
        }

        foreach ($settings['global']['defaults'] ?? [] as $capability => $model) {
            $values["global_defaults_{$capability}"] = $model;
        }

        return $values;
    }

    public function make(): StatamicBlueprint
    {
        return BlueprintFacade::make()->setContents([
            'sections' => [
                'global' => [
                    'display' => 'Global Settings',
                    'fields' => $this->globalFields(),
                ],
            ],
        ]);
    }

    private function globalFields(): array
    {
        return [
            [
                'handle' => 'global_system_prompt',
                'field' => [
                    'type' => 'textarea',
                    'display' => 'Global System Prompt',
                    'instructions' => 'This prompt will be prepended to all action system prompts. Use it to describe your brand voice, style guidelines, or other global context.',
                    'rows' => 4,
                ],
            ],
            [
                'handle' => 'global_defaults',
                'field' => [
                    'type' => 'section',
                    'display' => 'Default Models',
                    'instructions' => 'Default model to use for each capability when no action-specific override is set.',
                ],
            ],
            ...$this->typeDefaultFields(),
        ];
    }

    private function typeDefaultFields(): array
    {
        $types = Config::get('statamic.magic-actions.types', []);
        $fields = [];

        foreach ($types as $type => $config) {
            $options = $this->buildModelOptions($config['models'] ?? []);

            if (empty($options)) {
                continue;
            }

            $fields[] = [
                'handle' => "global_defaults_{$type}",
                'field' => [
                    'type' => 'select',
                    'display' => ucfirst($type).' Model',
                    'instructions' => "Default model for {$type} actions.",
                    'options' => $options,
                    'default' => $config['default'] ?? null,
                    'clearable' => true,
                    'placeholder' => 'Use config default',
                    'width' => 33,
                ],
            ];
        }

        return $fields;
    }

    private function buildModelOptions(array $models): array
    {
        $options = [];
        $providers = Config::get('statamic.magic-actions.providers', []);

        foreach ($models as $modelKey) {
            if (! str_contains($modelKey, '/')) {
                continue;
            }

            [$provider, $model] = explode('/', $modelKey, 2);

            $apiKey = $providers[$provider]['api_key'] ?? null;
            if (! $apiKey) {
                continue;
            }

            $options[$modelKey] = ucfirst($provider).': '.$model;
        }

        return $options;
    }
}
