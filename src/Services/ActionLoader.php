<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Settings;
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

        $type = $action->type();

        // Resolve model from global defaults
        $modelKey = Settings::get("global.defaults.{$type}")
            ?? Config::get("statamic.magic-actions.types.{$type}.default");

        // Parse provider/model from the combined key
        if (! str_contains($modelKey, '/')) {
            throw new InvalidArgumentException(
                "Invalid model key format: '{$modelKey}'. Expected format: 'provider/model'"
            );
        }
        [$provider, $model] = explode('/', $modelKey, 2);

        // Validate provider API key
        $apiKey = Config::get("statamic.magic-actions.providers.{$provider}.api_key");
        if (! $apiKey) {
            throw new MissingApiKeyException("API key not configured for provider '{$provider}'");
        }

        // Parameters from action class
        $parameters = $action->parameters();

        $result = [
            'type' => $type,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $parameters,
        ];

        // Render prompts for text and vision actions
        if ($type === 'text' || $type === 'vision') {
            // Build system prompt: global prepended + action default
            $globalSystemPrompt = Settings::get('global.system_prompt', '');
            $actionSystemPrompt = $action->system();

            $systemPrompt = mb_trim("{$globalSystemPrompt}\n\n{$actionSystemPrompt}");
            $result['systemPrompt'] = $this->renderBladeString($systemPrompt, $variables);

            // Use action's user prompt
            $result['userPrompt'] = $this->renderBladeString($action->prompt(), $variables);

            $schema = $action->schema();
            if ($schema !== null) {
                $result['schema'] = $schema;
            }
        }

        // Audio actions don't need system/user prompts in the same way
        if ($type === 'audio') {
            // Keep minimal - Prism::audio() handles transcription directly
        }

        return $result;
    }

    /**
     * Render a Blade template string with variables
     */
    private function renderBladeString(string $template, array $variables): string
    {
        return app('blade.compiler')->render($template, $variables);
    }
}
