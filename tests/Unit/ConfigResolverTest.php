<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Settings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Set up providers with API keys
    Config::set('statamic.magic-actions.providers', [
        'openai' => ['api_key' => 'test-openai-key'],
        'anthropic' => ['api_key' => 'test-anthropic-key'],
    ]);

    // Set up types with models and defaults
    Config::set('statamic.magic-actions.types', [
        'text' => [
            'models' => [
                'openai/gpt-4.1',
                'openai/gpt-4.1-mini',
                'anthropic/claude-sonnet-4-5',
            ],
            'default' => 'openai/gpt-4.1',
        ],
        'vision' => [
            'models' => [
                'openai/gpt-4.1',
                'anthropic/claude-sonnet-4-5',
            ],
            'default' => 'openai/gpt-4.1',
        ],
        'audio' => [
            'models' => [
                'openai/whisper-1',
            ],
            'default' => 'openai/whisper-1',
        ],
    ]);

    // Configure settings path to a test location
    Config::set('statamic.magic-actions.settings_path', storage_path('test-settings.yaml'));

    // Clean up any existing test settings file
    if (File::exists(Settings::path())) {
        File::delete(Settings::path());
    }
});

afterEach(function () {
    // Clean up test settings file
    if (File::exists(Settings::path())) {
        File::delete(Settings::path());
    }
});

// ============================================================================
// Settings Class Tests
// ============================================================================

describe('Settings class', function () {
    it('returns empty array when settings file does not exist', function () {
        expect(Settings::data())->toBe([]);
    });

    it('reads and parses YAML settings file', function () {
        Settings::save([
            'global' => [
                'system_prompt' => 'Test prompt',
            ],
        ]);

        expect(Settings::data())->toBe([
            'global' => [
                'system_prompt' => 'Test prompt',
            ],
        ]);
    });

    it('gets nested values with dot notation', function () {
        Settings::save([
            'global' => [
                'system_prompt' => 'Global prompt',
                'defaults' => [
                    'text' => 'anthropic/claude-sonnet-4-5',
                ],
            ],
        ]);

        expect(Settings::get('global.system_prompt'))->toBe('Global prompt');
        expect(Settings::get('global.defaults.text'))->toBe('anthropic/claude-sonnet-4-5');
    });

    it('returns default value when key does not exist', function () {
        expect(Settings::get('nonexistent.key', 'default'))->toBe('default');
    });

    it('creates directory if it does not exist when saving', function () {
        $path = storage_path('nested/dir/settings.yaml');
        Config::set('statamic.magic-actions.settings_path', $path);

        Settings::save(['test' => 'value']);

        expect(File::exists($path))->toBeTrue();

        // Cleanup
        File::deleteDirectory(storage_path('nested'));
    });
});

// ============================================================================
// Model Resolution Tests
// ============================================================================

describe('Model resolution', function () {
    it('uses config default when no user settings exist', function () {
        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        // extract-tags has type 'text', config default is 'openai/gpt-4.1'
        expect($result['provider'])->toBe('openai');
        expect($result['model'])->toBe('gpt-4.1');
    });

    it('uses user global default over config default', function () {
        Settings::save([
            'global' => [
                'defaults' => [
                    'text' => 'anthropic/claude-sonnet-4-5',
                ],
            ],
        ]);

        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        expect($result['provider'])->toBe('anthropic');
        expect($result['model'])->toBe('claude-sonnet-4-5');
    });

});

// ============================================================================
// System Prompt Resolution Tests
// ============================================================================

describe('System prompt resolution', function () {
    it('uses action default system prompt when no user settings exist', function () {
        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        expect($result['systemPrompt'])->toContain('content tagging expert');
    });

    it('prepends global system prompt to action system prompt', function () {
        Settings::save([
            'global' => [
                'system_prompt' => 'You are an assistant for Acme Corp.',
            ],
        ]);

        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        expect($result['systemPrompt'])->toContain('You are an assistant for Acme Corp.');
        expect($result['systemPrompt'])->toContain('content tagging expert');
    });

});

// ============================================================================
// User Prompt Resolution Tests
// ============================================================================

describe('User prompt resolution', function () {
    it('uses action default user prompt when no override exists', function () {
        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'My article content']);

        expect($result['userPrompt'])->toContain('My article content');
    });
});

// ============================================================================
// Parameters Stay on Action Class
// ============================================================================

describe('Parameters from action class', function () {
    it('uses parameters from action config method', function () {
        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        expect($result['parameters'])->toHaveKey('temperature');
        expect($result['parameters'])->toHaveKey('max_tokens');
        expect($result['parameters']['temperature'])->toBe(0.5);
        expect($result['parameters']['max_tokens'])->toBe(500);
    });
});

// ============================================================================
// Action Model Constraints Tests
// ============================================================================

describe('Action model constraints', function () {
    it('uses forced model from action when single model is specified', function () {
        // TranscribeAudio forces whisper-1
        $loader = app(ActionLoader::class);
        $result = $loader->load('transcribe-audio', []);

        expect($result['provider'])->toBe('openai');
        expect($result['model'])->toBe('whisper-1');
    });

    it('uses config defaults when action models() returns empty array', function () {
        // extract-tags has empty models() (default from BaseMagicAction)
        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        // Should use text type default from config
        expect($result['provider'])->toBe('openai');
        expect($result['model'])->toBe('gpt-4.1');
    });

    it('allows settings override when action models() returns empty array', function () {
        Settings::save([
            'global' => [
                'defaults' => [
                    'text' => 'anthropic/claude-sonnet-4-5',
                ],
            ],
        ]);

        $loader = app(ActionLoader::class);
        $result = $loader->load('extract-tags', ['text' => 'Sample text']);

        // Should use the setting since action doesn't constrain models
        expect($result['provider'])->toBe('anthropic');
        expect($result['model'])->toBe('claude-sonnet-4-5');
    });
});
