<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

final class ActionLoader
{
    /**
     * Load an action with variables rendered
     *
     * @param  string  $action  The action identifier
     * @param  array  $variables  Variables to render in templates
     * @return array Contains: action, provider, model, parameters, systemPrompt, userPrompt, schema (if exists)
     *
     * @throws RuntimeException If action config not found
     */
    public function load(string $action, array $variables = []): array
    {
        $magicAction = $this->loadMagicAction($action);
        if ($magicAction === null) {
            throw new RuntimeException("Action '{$action}' not found");
        }

        $result = $this->buildResultFromMagicAction($magicAction, $variables);
        $result['action'] = $magicAction;

        return $result;
    }

    /**
     * Check if an action exists
     *
     * @param  string  $action  The action identifier to check
     * @return bool True if action exists, false otherwise
     */
    public function exists(string $action): bool
    {
        return $this->loadMagicAction($action) !== null;
    }

    /**
     * Load MagicAction from user's app or addon's src directory
     */
    private function loadMagicAction(string $action): ?MagicAction
    {
        $className = $this->convertActionNameToClassName($action);

        // Try user's published version first
        $userClass = "App\\MagicActions\\{$className}";
        if (class_exists($userClass)) {
            return new $userClass();
        }

        // Fall back to addon's default
        $addonClass = "ElSchneider\\StatamicMagicActions\\MagicActions\\{$className}";
        if (class_exists($addonClass)) {
            return new $addonClass();
        }

        return null;
    }

    /**
     * Convert kebab-case action name to PascalCase class name
     */
    private function convertActionNameToClassName(string $action): string
    {
        return ActionRegistry::handleToClassName($action);
    }

    /**
     * Build result array from MagicAction instance
     */
    private function buildResultFromMagicAction(MagicAction $action, array $variables): array
    {
        // Validate variables against action rules
        $validator = Validator::make($variables, $action->rules());
        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid variables: '.implode(', ', $validator->errors()->all()));
        }

        $config = $action->config();
        $actionType = $config['type'] ?? null;
        $provider = $config['provider'] ?? null;
        $model = $config['model'] ?? null;
        $parameters = $config['parameters'] ?? [];

        // Validate provider API key
        if ($provider) {
            $apiKey = Config::get("statamic.magic-actions.providers.{$provider}.api_key");
            if (! $apiKey) {
                throw new MissingApiKeyException("API key not configured for provider '{$provider}'");
            }
        }

        $result = [
            'type' => $actionType,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $parameters,
        ];

        // Render prompts for text actions
        if ($actionType === 'text') {
            $result['systemPrompt'] = $this->renderBladeString($action->system(), $variables);
            $result['userPrompt'] = $this->renderBladeString($action->prompt(), $variables);

            $schema = $action->schema();
            if ($schema !== null) {
                $result['schema'] = $schema;
            }
        }

        // Audio actions don't need system/user prompts in the same way
        if ($actionType === 'audio') {
            // Keep minimal - Prism::audio() handles transcription directly
        }

        return $result;
    }

    /**
     * Render a Blade template string with variables
     */
    private function renderBladeString(string $template, array $variables): string
    {
        $compiled = Blade::compileString($template);
        extract($variables, EXTR_SKIP);
        ob_start();
        eval('?>'.$compiled);

        return ob_get_clean();
    }
}
