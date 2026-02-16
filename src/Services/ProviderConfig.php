<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Config;

final class ProviderConfig
{
    public function all(): array
    {
        $providers = Config::get('statamic.magic-actions.providers', []);

        return is_array($providers) ? $providers : [];
    }

    public function hasApiKey(string $provider): bool
    {
        return $this->apiKey($provider) !== null;
    }

    public function apiKey(string $provider): ?string
    {
        return $this->apiKeyFromConfig($this->providerConfig($provider));
    }

    public function apiKeyEnvVar(string $provider): string
    {
        $envVar = $this->providerConfig($provider)['env'] ?? null;

        if ($this->isFilledString($envVar)) {
            return mb_trim($envVar);
        }

        return $this->fallbackEnvVarForProvider($provider);
    }

    public function configuredProviderNames(): array
    {
        return $this->providerNamesByApiKeyState(true);
    }

    public function missingProviderNames(): array
    {
        return $this->providerNamesByApiKeyState(false);
    }

    private function providerNamesByApiKeyState(bool $configured): array
    {
        $providers = [];

        foreach ($this->all() as $provider => $config) {
            if (! $this->isFilledString($provider)) {
                continue;
            }

            $providerConfig = is_array($config) ? $config : [];
            $hasApiKey = $this->apiKeyFromConfig($providerConfig) !== null;

            if ($hasApiKey === $configured) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    private function providerConfig(string $provider): array
    {
        $providerConfig = $this->all()[$provider] ?? [];

        return is_array($providerConfig) ? $providerConfig : [];
    }

    private function apiKeyFromConfig(array $config): ?string
    {
        $apiKey = $config['api_key'] ?? null;

        if (! $this->isFilledString($apiKey)) {
            return null;
        }

        return mb_trim($apiKey);
    }

    private function fallbackEnvVarForProvider(string $provider): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $provider);
        $normalized = is_string($normalized) ? mb_trim($normalized, '_') : '';
        $normalized = $normalized !== '' ? mb_strtoupper($normalized) : 'PROVIDER';

        return $normalized.'_API_KEY';
    }

    private function isFilledString(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }
}
