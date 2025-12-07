<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;

final class ActionLoader
{
    /**
     * Load an action with variables rendered
     *
     * @param string $action The action identifier
     * @param array $variables Variables to render in templates
     * @return array Contains: provider, model, parameters, systemPrompt, userPrompt, schema (if exists)
     * @throws \RuntimeException If action config not found
     */
    public function load(string $action, array $variables = []): array
    {
        $actionConfig = $this->findActionConfig($action);

        if (!$actionConfig) {
            throw new \RuntimeException("Action '{$action}' not found in configuration");
        }

        $actionType = $actionConfig['type'];
        $provider = $actionConfig['provider'];
        $model = $actionConfig['model'];
        $parameters = $actionConfig['parameters'] ?? [];

        // Validate provider API key
        $apiKey = Config::get("statamic.magic-actions.providers.{$provider}.api_key");
        if (!$apiKey) {
            throw new MissingApiKeyException("API key not configured for provider '{$provider}'");
        }

        $result = [
            'type' => $actionType,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $parameters,
        ];

        // Load prompts for text actions
        if ($actionType === 'text') {
            $result['systemPrompt'] = $this->loadView("magic-actions::{$action}.system", $variables);
            $result['userPrompt'] = $this->loadView("magic-actions::{$action}.prompt", $variables);

            // Load schema if it exists
            $schemaPath = resource_path("actions/{$action}/schema.php");
            if (file_exists($schemaPath)) {
                $result['schema'] = require $schemaPath;
            }
        }

        // Audio actions don't need system/user prompts in the same way
        if ($actionType === 'audio') {
            // Keep minimal - Prism::audio() handles transcription directly
        }

        return $result;
    }

    /**
     * Check if an action exists in configuration
     */
    public function exists(string $action): bool
    {
        return $this->findActionConfig($action) !== null;
    }

    /**
     * Find action configuration across all capabilities
     */
    private function findActionConfig(string $action): ?array
    {
        $actions = Config::get('statamic.magic-actions.actions', []);

        foreach ($actions as $type => $typeActions) {
            if (isset($typeActions[$action])) {
                return array_merge(
                    ['type' => $type],
                    $typeActions[$action]
                );
            }
        }

        return null;
    }

    /**
     * Load and render a view with variables
     */
    private function loadView(string $viewName, array $variables): string
    {
        return View::make($viewName, $variables)->render();
    }
}
