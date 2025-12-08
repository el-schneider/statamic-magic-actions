<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Facades\Asset;

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
                'alt-text' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4o',
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
    ]);

    Cache::flush();
});

// ============================================================================
// Job dispatch
// ============================================================================

it('can be dispatched to the queue', function () {
    Queue::fake();

    ProcessPromptJob::dispatch('test-job-id', 'propose-title', ['content' => 'test']);

    Queue::assertPushed(ProcessPromptJob::class);
});

// ============================================================================
// Cache status lifecycle
// ============================================================================

it('updates cache to completed with structured data on success', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Generated Title',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['text' => 'Sample article']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data'])->toBe('Generated Title');
});

it('updates cache to failed with error on exception', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'non-existent-action', []);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString()->not()->toBeEmpty();
});

it('handles MissingApiKeyException', function () {
    Config::set('statamic.magic-actions.providers.openai.api_key', null);
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString()->not()->toBeEmpty();
});

// ============================================================================
// Audio prompts - error handling
// ============================================================================

it('fails when audio prompt has no asset path', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', []);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
});

it('fails when audio asset is not found', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Asset::shouldReceive('find')->with('assets/missing.mp3')->andReturn(null);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], 'assets/missing.mp3');
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
});

// ============================================================================
// Text prompts without assets
// ============================================================================

it('handles text prompts without asset path', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['title' => 'Generated Title']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-text', 'propose-title', ['text' => 'Sample content']);
    $job->handle($loader);

    $cached = Cache::get('magic_actions_job_test-job-text');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toBe('Generated Title');
});

it('handles missing image asset gracefully', function () {
    Asset::shouldReceive('find')->with('assets::nonexistent.jpg')->andReturn(null);

    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Generated text']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-789', 'alt-text', ['text' => 'Describe'], 'assets::nonexistent.jpg');
    $job->handle($loader);

    // Should still complete but without image media
    expect(Cache::get('magic_actions_job_test-job-789')['status'])->toBe('completed');
});
