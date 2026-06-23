<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Settings;

use ElSchneider\StatamicMagicActions\Services\ProviderConfig;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint as StatamicBlueprint;

final class Blueprint
{
    public function __construct(private readonly ProviderConfig $providerConfig) {}

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
                    'display' => __('magic-actions::magic-actions.settings.global'),
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
                    'display' => __('magic-actions::magic-actions.settings.system_prompt'),
                    'instructions' => __('magic-actions::magic-actions.settings.system_prompt_instructions'),
                    'rows' => 4,
                ],
            ],
            [
                'handle' => 'global_defaults',
                'field' => [
                    'type' => 'section',
                    'display' => __('magic-actions::magic-actions.settings.default_models'),
                    'instructions' => $this->defaultModelsInstructions(),
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
            if (! is_array($config)) {
                continue;
            }

            $options = $this->buildModelOptions($config['models'] ?? []);

            if ($options === []) {
                continue;
            }

            $fields[] = [
                'handle' => "global_defaults_{$type}",
                'field' => [
                    'type' => 'select',
                    'display' => ucfirst($type).' Model',
                    'instructions' => __('magic-actions::magic-actions.settings.model_field_instructions', ['type' => $type]),
                    'options' => $options,
                    'default' => $config['default'] ?? null,
                    'clearable' => true,
                    'placeholder' => __('magic-actions::magic-actions.settings.model_field_placeholder'),
                    'width' => 33,
                ],
            ];
        }

        return $fields;
    }

    private function defaultModelsInstructions(): string
    {
        $providers = array_keys($this->providerConfig->all());
        $configured = $this->providerConfig->configuredProviderNames();
        $missing = $this->providerConfig->missingProviderNames();

        $base = __('magic-actions::magic-actions.settings.default_models_base');

        if ($providers === []) {
            return $base.' '.__('magic-actions::magic-actions.settings.default_models_no_providers');
        }

        if ($configured === []) {
            $envKeys = $this->providerEnvKeyList($providers, ', ');

            return $base.' '.__('magic-actions::magic-actions.settings.default_models_no_keys', ['keys' => $envKeys]);
        }

        if ($missing !== []) {
            $envKeys = $this->providerEnvKeyList($missing, ' or ');

            return $base.' '.__('magic-actions::magic-actions.settings.default_models_unlock', ['keys' => $envKeys]);
        }

        return $base;
    }

    private function providerEnvKeyList(array $providers, string $separator): string
    {
        $envKeys = [];

        foreach ($providers as $provider) {
            if (! $this->isFilledString($provider)) {
                continue;
            }

            $envKeys[] = '`'.$this->providerConfig->apiKeyEnvVar($provider).'`';
        }

        return implode($separator, $envKeys);
    }

    private function buildModelOptions(array $models): array
    {
        $options = [];

        foreach ($models as $modelKey) {
            if (! $this->isFilledString($modelKey) || ! str_contains($modelKey, '/')) {
                continue;
            }

            [$provider, $model] = explode('/', $modelKey, 2);

            if (! $this->providerConfig->hasApiKey($provider)) {
                continue;
            }

            $options[$modelKey] = ucfirst($provider).': '.$model;
        }

        return $options;
    }

    private function isFilledString(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }
}
