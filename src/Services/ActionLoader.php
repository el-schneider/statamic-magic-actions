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
    private const PROMPT_TYPES = ['text', 'vision'];

    public function __construct(private readonly ProviderConfig $providerConfig) {}

    public function load(string $action, array $variables = []): array
    {
        $magicAction = $this->loadMagicAction($action);
        if ($magicAction === null) {
            throw new RuntimeException($this->t('errors.action_loader.action_not_found', ['action' => $action]));
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
            throw new InvalidArgumentException($this->t('errors.action_loader.invalid_variables', [
                'errors' => implode(', ', $validator->errors()->all()),
            ]));
        }

        $type = $action->type();
        $modelKey = $this->resolveModelKeyForType($type);

        if ($modelKey === null) {
            throw new InvalidArgumentException($this->t('errors.action_loader.missing_model_configuration', [
                'type' => $type,
            ]));
        }

        if (! str_contains($modelKey, '/')) {
            throw new InvalidArgumentException($this->t('errors.action_loader.invalid_model_key_format', [
                'model' => $modelKey,
            ]));
        }

        [$provider, $model] = explode('/', $modelKey, 2);

        if (! $this->providerConfig->hasApiKey($provider)) {
            $envVar = $this->providerConfig->apiKeyEnvVar($provider);

            throw new MissingApiKeyException($this->t('errors.action_loader.missing_provider_api_key', [
                'provider' => $provider,
                'env' => $envVar,
            ]));
        }

        $result = [
            'type' => $type,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $action->parameters(),
        ];

        if (! in_array($type, self::PROMPT_TYPES, true)) {
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

    private function resolveModelKeyForType(string $type): ?string
    {
        $candidates = [
            Settings::get("global.defaults.{$type}"),
            Config::get("statamic.magic-actions.types.{$type}.default"),
            $this->firstModelKeyForType($type),
        ];

        foreach ($candidates as $candidate) {
            if ($this->isFilledString($candidate)) {
                return $candidate;
            }
        }

        return null;
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

    private function firstModelKeyForType(string $type): ?string
    {
        $models = Config::get("statamic.magic-actions.types.{$type}.models", []);

        if (! is_array($models)) {
            return null;
        }

        foreach ($models as $modelKey) {
            if ($this->isFilledString($modelKey)) {
                return $modelKey;
            }
        }

        return null;
    }

    private function isFilledString(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }

    private function t(string $key, array $replace = []): string
    {
        return __('magic-actions::magic-actions.'.$key, $replace);
    }
}
