<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Services\ProviderConfig;
use Illuminate\Support\Facades\Config;

it('uses provider env from config for api key hints', function () {
    Config::set('statamic.magic-actions.providers', [
        'openrouter' => [
            'api_key' => null,
            'env' => 'OPENROUTER_KEY',
        ],
    ]);

    $providerConfig = app(ProviderConfig::class);

    expect($providerConfig->apiKeyEnvVar('openrouter'))->toBe('OPENROUTER_KEY');
});

it('falls back to normalized provider name for api key hints', function () {
    Config::set('statamic.magic-actions.providers', [
        'open-router' => [
            'api_key' => null,
        ],
    ]);

    $providerConfig = app(ProviderConfig::class);

    expect($providerConfig->apiKeyEnvVar('open-router'))->toBe('OPEN_ROUTER_API_KEY');
});

it('separates configured and missing providers from config', function () {
    Config::set('statamic.magic-actions.providers', [
        'openai' => ['api_key' => 'sk-test'],
        'mistral' => ['api_key' => ''],
        'gemini' => ['api_key' => null],
    ]);

    $providerConfig = app(ProviderConfig::class);

    expect($providerConfig->configuredProviderNames())->toBe(['openai']);
    expect($providerConfig->missingProviderNames())->toBe(['mistral', 'gemini']);
});
