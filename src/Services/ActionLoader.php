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

    public function exists(string $action): bool
    {
        return $this->loadMagicAction($action) !== null;
    }

    /**
     * Get a MagicAction instance without loading provider/model config.
     */
    public function getMagicAction(string $action): ?MagicAction
    {
        return $this->loadMagicAction($action);
    }

    private function loadMagicAction(string $action): ?MagicAction
    {
        $className = ActionRegistry::handleToClassName($action);

        $userClass = "App\\MagicActions\\{$className}";
        if (class_exists($userClass)) {
            return new $userClass();
        }

        $addonClass = "ElSchneider\\StatamicMagicActions\\MagicActions\\{$className}";
        if (class_exists($addonClass)) {
            return new $addonClass();
        }

        return null;
    }

    private function buildResultFromMagicAction(MagicAction $action, array $variables): array
    {
        $validator = Validator::make($variables, $action->rules());
        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid variables: '.implode(', ', $validator->errors()->all()));
        }

        $type = $action->type();

        $modelKey = Settings::get("global.defaults.{$type}")
            ?? Config::get("statamic.magic-actions.types.{$type}.default", $this->defaultModelKeyForType($type));

        if (! is_string($modelKey) || mb_trim($modelKey) === '') {
            throw new InvalidArgumentException(
                "Missing model configuration for type '{$type}'. Set statamic.magic-actions.types.{$type}.default."
            );
        }

        if (! str_contains($modelKey, '/')) {
            throw new InvalidArgumentException(
                "Invalid model key format: '{$modelKey}'. Expected format: 'provider/model'"
            );
        }
        [$provider, $model] = explode('/', $modelKey, 2);

        $apiKey = Config::get("statamic.magic-actions.providers.{$provider}.api_key", '');
        if (! is_string($apiKey) || mb_trim($apiKey) === '') {
            $envVar = $this->providerApiKeyEnvVar($provider);

            throw new MissingApiKeyException(
                "API key not configured for provider '{$provider}'. Set {$envVar} in your .env file."
            );
        }

        $result = [
            'type' => $type,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $action->parameters(),
        ];

        if (! in_array($type, ['text', 'vision'], true)) {
            return $result;
        }

        $result['systemPrompt'] = $this->renderBladeString($this->buildSystemPrompt($action), $variables);
        $result['userPrompt'] = $this->renderBladeString($action->prompt(), $variables);

        $schema = $action->schema();
        if ($schema !== null) {
            $result['schema'] = $schema;
        }

        return $result;
    }

    private function renderBladeString(string $template, array $variables): string
    {
        return app('blade.compiler')->render($template, $variables);
    }

    private function buildSystemPrompt(MagicAction $action): string
    {
        $globalSystemPrompt = Settings::get('global.system_prompt', '');
        $actionSystemPrompt = $action->system();

        return implode("\n\n", array_filter([$globalSystemPrompt, $actionSystemPrompt]));
    }

    private function defaultModelKeyForType(string $type): string
    {
        return match ($type) {
            'audio' => 'openai/whisper-1',
            default => 'openai/gpt-4.1',
        };
    }

    private function providerApiKeyEnvVar(string $provider): string
    {
        return match (mb_strtolower($provider)) {
            'openai' => 'OPENAI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            default => mb_strtoupper($provider).'_API_KEY',
        };
    }
}
