<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Facades\Asset;

function testContext(): array
{
    return [
        'type' => 'entry',
        'id' => 'test-entry-id',
        'field' => 'title',
    ];
}

function createJobInTracker(JobTracker $tracker, string $jobId, string $action = 'propose-title'): void
{
    $ctx = testContext();
    $tracker->createJob($jobId, $action, $ctx['type'], $ctx['id'], $ctx['field']);
}

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

    Cache::flush();
});

// ============================================================================
// Job dispatch
// ============================================================================

it('can be dispatched to the queue', function () {
    Queue::fake();

    ProcessPromptJob::dispatch('test-job-id', 'propose-title', ['content' => 'test'], null, testContext());

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
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-id', 'propose-title');

    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['text' => 'Sample article'], null, testContext());
    $job->handle($loader, $jobTracker);

    $cachedData = $jobTracker->getJob('test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data'])->toBe('Generated Title');
});

it('updates cache to failed with error on exception', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-id', 'non-existent-action');

    $job = new ProcessPromptJob('test-job-id', 'non-existent-action', [], null, testContext());
    $job->handle($loader, $jobTracker);

    $cachedData = $jobTracker->getJob('test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['message'])->toBeString()->not()->toBeEmpty();
});

it('handles MissingApiKeyException', function () {
    Config::set('statamic.magic-actions.providers.openai.api_key', null);
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-id', 'propose-title');

    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test'], null, testContext());
    $job->handle($loader, $jobTracker);

    $cachedData = $jobTracker->getJob('test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['message'])->toBeString()->not()->toBeEmpty();
});

// ============================================================================
// Audio prompts - error handling
// ============================================================================

it('fails when audio prompt has no asset path', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-id', 'transcribe-audio');

    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], null, testContext());
    $job->handle($loader, $jobTracker);

    $cachedData = $jobTracker->getJob('test-job-id');
    expect($cachedData['status'])->toBe('failed');
});

it('fails when audio asset is not found', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Asset::shouldReceive('find')->with('assets/missing.mp3')->andReturn(null);

    $loader = app(ActionLoader::class);
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-id', 'transcribe-audio');

    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], 'assets/missing.mp3', testContext());
    $job->handle($loader, $jobTracker);

    $cachedData = $jobTracker->getJob('test-job-id');
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
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-text', 'propose-title');

    $job = new ProcessPromptJob('test-job-text', 'propose-title', ['text' => 'Sample content'], null, testContext());
    $job->handle($loader, $jobTracker);

    $cached = $jobTracker->getJob('test-job-text');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toBe('Generated Title');
});

it('handles missing image asset gracefully', function () {
    Asset::shouldReceive('find')->with('assets::nonexistent.jpg')->andReturn(null);

    Prism::fake([
        StructuredResponseFake::make()->withStructured(['alt_text' => 'Generated text']),
    ]);

    $loader = app(ActionLoader::class);
    $jobTracker = app(JobTracker::class);
    createJobInTracker($jobTracker, 'test-job-789', 'alt-text');

    $job = new ProcessPromptJob('test-job-789', 'alt-text', ['text' => 'Describe'], 'assets::nonexistent.jpg', testContext());
    $job->handle($loader, $jobTracker);

    // Should still complete but without image media
    expect($jobTracker->getJob('test-job-789')['status'])->toBe('completed');
});
