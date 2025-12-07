<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    // Override the entire config to prevent FieldConfigService errors
    // Note: The production config uses 'action' but FieldConfigService expects 'handle'
    // This is a known issue - using 'handle' here for tests to work
    Config::set('statamic.magic-actions', [
        'providers' => [
            'openai' => [
                'api_key' => 'test-openai-key',
            ],
            'anthropic' => [
                'api_key' => 'test-anthropic-key',
            ],
        ],
        'actions' => [
            'text' => [
                'propose-title' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                    'parameters' => [
                        'temperature' => 0.7,
                        'max_tokens' => 200,
                    ],
                ],
                'extract-meta-description' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                    'parameters' => [
                        'temperature' => 0.7,
                        'max_tokens' => 300,
                    ],
                ],
                'alt-text' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4-vision-preview',
                    'parameters' => [
                        'temperature' => 0.7,
                        'max_tokens' => 1000,
                    ],
                ],
            ],
            'audio' => [
                'transcribe-audio' => [
                    'provider' => 'openai',
                    'model' => 'whisper-1',
                    'parameters' => [
                        'language' => 'en',
                    ],
                ],
            ],
        ],
        'fieldtypes' => [
            'Statamic\Fieldtypes\Text' => [
                'actions' => [
                    [
                        'title' => 'Propose Title',
                        'handle' => 'propose-title',
                    ],
                ],
            ],
        ],
    ]);
});

it('loads a text action with all required fields', function () {
    $loader = app(ActionLoader::class);

    $result = $loader->load('propose-title', ['content' => 'Sample article content']);

    expect($result)->toHaveKeys(['type', 'provider', 'model', 'parameters', 'systemPrompt', 'userPrompt']);
    expect($result['type'])->toBe('text');
    expect($result['provider'])->toBe('openai');
    expect($result['model'])->toBe('gpt-4');
    expect($result['parameters'])->toBeArray();
    expect($result['parameters']['temperature'])->toBe(0.7);
    expect($result['parameters']['max_tokens'])->toBe(200);
    expect($result['systemPrompt'])->toBeString();
    expect($result['userPrompt'])->toBeString();
    expect($result['systemPrompt'])->toContain('content expert');
});

it('loads a text action with schema when schema file exists', function () {
    // Create the schema file in the test resource path
    $testResourcePath = resource_path('actions/propose-title');
    if (! is_dir($testResourcePath)) {
        mkdir($testResourcePath, 0755, true);
    }
    copy(
        __DIR__.'/../../resources/actions/propose-title/schema.php',
        resource_path('actions/propose-title/schema.php')
    );

    $loader = app(ActionLoader::class);

    $result = $loader->load('propose-title', ['content' => 'Sample content']);

    expect($result)->toHaveKey('schema');
    expect($result['schema'])->toBeInstanceOf(Prism\Prism\Schema\ObjectSchema::class);
    expect($result['schema']->name)->toBe('title_response');
});

it('loads a text action without schema when schema file does not exist', function () {
    $loader = app(ActionLoader::class);

    // Use a text action that doesn't have a schema file (extract-meta-description)
    $result = $loader->load('extract-meta-description', ['text' => 'Sample text']);

    expect($result)->not->toHaveKey('schema');
});

it('loads an audio action with correct type', function () {
    $loader = app(ActionLoader::class);

    $result = $loader->load('transcribe-audio', []);

    expect($result)->toHaveKeys(['type', 'provider', 'model', 'parameters']);
    expect($result['type'])->toBe('audio');
    expect($result['provider'])->toBe('openai');
    expect($result['model'])->toBe('whisper-1');
    expect($result['parameters'])->toBeArray();
    expect($result['parameters']['language'])->toBe('en');
});

it('does not include systemPrompt or userPrompt for audio actions', function () {
    $loader = app(ActionLoader::class);

    $result = $loader->load('transcribe-audio', []);

    expect($result)->not->toHaveKey('systemPrompt');
    expect($result)->not->toHaveKey('userPrompt');
});

it('renders template variables in prompts', function () {
    $loader = app(ActionLoader::class);

    $variables = [
        'content' => 'My custom article content',
    ];

    $result = $loader->load('propose-title', $variables);

    // The prompt template contains {{ $content }}
    expect($result['userPrompt'])->toContain('My custom article content');
});

it('throws RuntimeException when loading non-existent action', function () {
    $loader = app(ActionLoader::class);

    expect(fn () => $loader->load('non-existent-action', []))
        ->toThrow(RuntimeException::class, "Action 'non-existent-action' not found in configuration");
});

it('returns true when checking if action exists', function () {
    $loader = app(ActionLoader::class);

    expect($loader->exists('propose-title'))->toBeTrue();
    expect($loader->exists('transcribe-audio'))->toBeTrue();
    expect($loader->exists('alt-text'))->toBeTrue();
});

it('returns false when checking if non-existent action exists', function () {
    $loader = app(ActionLoader::class);

    expect($loader->exists('non-existent-action'))->toBeFalse();
    expect($loader->exists('another-missing-action'))->toBeFalse();
});

it('includes all parameters from config in loaded action', function () {
    $loader = app(ActionLoader::class);

    $result = $loader->load('extract-meta-description', ['text' => 'Sample text']);

    expect($result['parameters'])->toBeArray();
    expect($result['parameters'])->toHaveKey('temperature');
    expect($result['parameters'])->toHaveKey('max_tokens');
    expect($result['parameters']['temperature'])->toBe(0.7);
    expect($result['parameters']['max_tokens'])->toBe(300);
});

it('throws MissingApiKeyException when provider API key is not configured', function () {
    // Clear the API key
    Config::set('statamic.magic-actions.providers.openai.api_key', null);

    $loader = app(ActionLoader::class);

    expect(fn () => $loader->load('propose-title', []))
        ->toThrow(MissingApiKeyException::class, "API key not configured for provider 'openai'");
});

it('finds actions across different capability types', function () {
    $loader = app(ActionLoader::class);

    // Text action
    $textResult = $loader->load('propose-title', ['content' => 'Sample content']);
    expect($textResult['type'])->toBe('text');

    // Audio action
    $audioResult = $loader->load('transcribe-audio', []);
    expect($audioResult['type'])->toBe('audio');
});

it('loads action with empty parameters when none are configured', function () {
    // Temporarily remove parameters from an existing action
    Config::set('statamic.magic-actions.actions.text.propose-title.parameters', null);

    $loader = app(ActionLoader::class);
    $result = $loader->load('propose-title', ['content' => 'Sample content']);

    expect($result['parameters'])->toBeArray();
    expect($result['parameters'])->toBeEmpty();
});
