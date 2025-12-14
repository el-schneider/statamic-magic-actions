<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('statamic.magic-actions', [
        'providers' => [
            'openai' => [
                'api_key' => 'test-openai-key',
            ],
            'anthropic' => [
                'api_key' => 'test-anthropic-key',
            ],
        ],
        'types' => [
            'text' => [
                'models' => ['openai/gpt-4.1', 'openai/gpt-4.1-mini'],
                'default' => 'openai/gpt-4.1',
            ],
            'vision' => [
                'models' => ['openai/gpt-4.1'],
                'default' => 'openai/gpt-4.1',
            ],
            'audio' => [
                'models' => ['openai/whisper-1'],
                'default' => 'openai/whisper-1',
            ],
        ],
    ]);
});

// ============================================================================
// Loading text actions
// ============================================================================

it('loads a text action with all required fields', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('propose-title', ['text' => 'Sample article content']);

    expect($result)->toHaveKeys(['type', 'provider', 'model', 'parameters', 'systemPrompt', 'userPrompt']);
    expect($result['type'])->toBe('text');
    expect($result['provider'])->toBe('openai');
    expect($result['model'])->toBe('gpt-4.1'); // Uses type default
    expect($result['parameters']['temperature'])->toBe(0.7);
    expect($result['parameters']['max_tokens'])->toBe(200);
    expect($result['systemPrompt'])->toContain('content expert');
});

it('loads a text action with schema when schema file exists', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('propose-title', ['text' => 'Sample content']);

    expect($result)->toHaveKey('schema');
    expect($result['schema'])->toBeInstanceOf(Prism\Prism\Schema\ObjectSchema::class);
    expect($result['schema']->name)->toBe('title_response');
});

// Note: All built-in actions have schemas, so we test audio action which doesn't have one
it('audio action does not include schema', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('transcribe-audio', []);

    expect($result)->not->toHaveKey('schema');
});

it('renders template variables in prompts', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('propose-title', ['text' => 'My custom article content']);

    expect($result['userPrompt'])->toContain('My custom article content');
});

it('loads action with parameters from MagicAction config', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('propose-title', ['text' => 'Sample content']);

    expect($result['parameters'])->toBeArray();
    expect($result['parameters'])->toHaveKeys(['temperature', 'max_tokens']);
});

// ============================================================================
// Loading audio actions
// ============================================================================

it('loads an audio action with correct type', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('transcribe-audio', []);

    expect($result)->toHaveKeys(['type', 'provider', 'model', 'parameters']);
    expect($result['type'])->toBe('audio');
    expect($result['provider'])->toBe('openai');
    expect($result['model'])->toBe('whisper-1');
    expect($result['parameters']['language'])->toBe('en');
    expect($result)->not->toHaveKey('systemPrompt');
    expect($result)->not->toHaveKey('userPrompt');
});

// ============================================================================
// Action existence checks
// ============================================================================

it('returns true for existing actions', function () {
    $loader = app(ActionLoader::class);

    expect($loader->exists('propose-title'))->toBeTrue();
    expect($loader->exists('transcribe-audio'))->toBeTrue();
    expect($loader->exists('alt-text'))->toBeTrue();
});

it('returns false for non-existent actions', function () {
    $loader = app(ActionLoader::class);

    expect($loader->exists('non-existent-action'))->toBeFalse();
});

// ============================================================================
// Error handling
// ============================================================================

it('throws RuntimeException for non-existent action', function () {
    $loader = app(ActionLoader::class);

    expect(fn () => $loader->load('non-existent-action', []))
        ->toThrow(RuntimeException::class, "Action 'non-existent-action' not found");
});

it('throws MissingApiKeyException when provider API key is not configured', function () {
    Config::set('statamic.magic-actions.providers.openai.api_key', null);

    $loader = app(ActionLoader::class);

    expect(fn () => $loader->load('propose-title', ['text' => 'Sample content']))
        ->toThrow(MissingApiKeyException::class, "API key not configured for provider 'openai'");
});

// ============================================================================
// Model constraints
// ============================================================================

it('uses forced model from TranscribeAudio action', function () {
    $loader = app(ActionLoader::class);
    $result = $loader->load('transcribe-audio', []);

    expect($result['provider'])->toBe('openai');
    expect($result['model'])->toBe('whisper-1');
});
