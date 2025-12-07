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
                'structured-action' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                    'parameters' => [
                        'temperature' => 0.5,
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
    expect($cachedData['data']['title'])->toBe('Generated Title');
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
// Audio prompts
// ============================================================================

it('handles audio prompts correctly', function () {
    $asset = Mockery::mock();
    $asset->shouldReceive('url')->andReturn('https://example.com/audio.mp3');
    Asset::shouldReceive('find')->with('assets/audio.mp3')->andReturn($asset);

    Prism::fake();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], 'assets/audio.mp3');
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data']['text'])->toBe('fake transcribed text');
});

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
// Image format handling
// ============================================================================

it('handles image URLs', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Response']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'alt-text', [
        'text' => 'Describe',
        'image' => 'https://example.com/test.jpg',
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('completed');
});

it('handles base64 data URIs', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Response']),
    ]);

    $base64Image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'alt-text', [
        'text' => 'Describe',
        'image' => $base64Image,
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('completed');
});

it('handles local file paths for images', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Response']),
    ]);

    $imagePath = __DIR__.'/../__fixtures__/media/test-image.png';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'alt-text', [
        'text' => 'Describe',
        'image' => $imagePath,
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('completed');
});

it('fails for invalid image format', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'alt-text', [
        'text' => 'Describe',
        'image' => '/path/to/nonexistent/image.jpg',
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('failed');
});

// ============================================================================
// Document format handling
// ============================================================================

it('handles document URLs', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['title' => 'Response']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', [
        'document' => 'https://example.com/document.pdf',
        'text' => 'test',
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('completed');
});

it('handles local file paths for documents', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['title' => 'Response']),
    ]);

    $documentPath = __DIR__.'/../__fixtures__/media/sample.txt';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', [
        'document' => $documentPath,
        'text' => 'test',
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('completed');
});

it('fails for invalid document format', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', [
        'document' => '/path/to/nonexistent/document.pdf',
        'text' => 'test',
    ]);
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-id')['status'])->toBe('failed');
});

// ============================================================================
// Asset resolution for vision actions
// ============================================================================

it('resolves asset path to url for vision actions', function () {
    $assetMock = Mockery::mock();
    $assetMock->shouldReceive('url')->andReturn('https://example.test/assets/image.jpg');

    Asset::shouldReceive('find')->with('assets::image.jpg')->andReturn($assetMock);

    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Generated alt text']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-123', 'alt-text', ['text' => 'Describe'], 'assets::image.jpg');
    $job->handle($loader);

    $cached = Cache::get('magic_actions_job_test-job-123');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toHaveKey('alt_text');
});

it('explicit image variable takes precedence over asset path', function () {
    // Asset::find should not be called because explicit image is provided
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Image description']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-456',
        'alt-text',
        ['text' => 'Describe', 'image' => 'https://example.test/explicit.jpg'],
        'assets::ignored.jpg'
    );
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-456')['status'])->toBe('completed');
});

it('handles missing asset gracefully when explicit image provided', function () {
    Asset::shouldReceive('find')->with('assets::nonexistent.jpg')->andReturn(null);

    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Generated text']),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-789',
        'alt-text',
        ['text' => 'Describe', 'image' => 'https://fallback.test/image.jpg'],
        'assets::nonexistent.jpg'
    );
    $job->handle($loader);

    expect(Cache::get('magic_actions_job_test-job-789')['status'])->toBe('completed');
});
