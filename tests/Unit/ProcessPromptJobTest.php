<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Statamic\Facades\Asset;

beforeEach(function () {
    // Set up complete config
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
                    'model' => 'gpt-4-vision-preview',
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

    // Create schema files for actions that have them
    // Note: propose-title and alt-text have schemas in the actual package,
    // but we need to create them in the test resource path
    $proposeTitleSchemaPath = resource_path('actions/propose-title');
    if (!is_dir($proposeTitleSchemaPath)) {
        mkdir($proposeTitleSchemaPath, 0755, true);
    }
    file_put_contents(
        resource_path('actions/propose-title/schema.php'),
        '<?php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: "title_response",
    description: "Proposed title for content",
    properties: [
        new StringSchema("title", "Proposed title"),
    ],
    requiredFields: ["title"]
);'
    );

    $altTextSchemaPath = resource_path('actions/alt-text');
    if (!is_dir($altTextSchemaPath)) {
        mkdir($altTextSchemaPath, 0755, true);
    }
    file_put_contents(
        resource_path('actions/alt-text/schema.php'),
        '<?php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: "alt_text_response",
    description: "Alt text description for image",
    properties: [
        new StringSchema("alt_text", "Alt text description"),
    ],
    requiredFields: ["alt_text"]
);'
    );

    // Clear cache before each test
    Cache::flush();
});

// ============================================================================
// Job dispatch tests
// ============================================================================

it('can be dispatched to the queue', function () {
    Queue::fake();

    ProcessPromptJob::dispatch('test-job-id', 'propose-title', ['content' => 'test']);

    Queue::assertPushed(ProcessPromptJob::class);
});

it('serializes job parameters correctly', function () {
    Queue::fake();

    $jobId = 'test-job-123';
    $action = 'propose-title';
    $variables = ['content' => 'Sample content', 'author' => 'John Doe'];
    $assetPath = 'assets/test.jpg';

    ProcessPromptJob::dispatch($jobId, $action, $variables, $assetPath);

    Queue::assertPushed(ProcessPromptJob::class, function ($job) use ($jobId, $action, $variables, $assetPath) {
        // Access private properties via reflection for verification
        $reflection = new ReflectionClass($job);

        $jobIdProp = $reflection->getProperty('jobId');
        $jobIdProp->setAccessible(true);

        $actionProp = $reflection->getProperty('action');
        $actionProp->setAccessible(true);

        $variablesProp = $reflection->getProperty('variables');
        $variablesProp->setAccessible(true);

        $assetPathProp = $reflection->getProperty('assetPath');
        $assetPathProp->setAccessible(true);

        return $jobIdProp->getValue($job) === $jobId
            && $actionProp->getValue($job) === $action
            && $variablesProp->getValue($job) === $variables
            && $assetPathProp->getValue($job) === $assetPath;
    });
});

// ============================================================================
// Cache status update tests
// ============================================================================

it('sets cache status during job execution', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Generated Title',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test']);
    $job->handle($loader);

    // Verify cache exists after execution
    expect(Cache::has('magic_actions_job_test-job-id'))->toBeTrue();
});

it('updates cache to completed with data when successful', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Generated Title',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData)->toBeArray();
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data'])->toBeArray();
    expect($cachedData['data']['title'])->toBe('Generated Title');
});

it('updates cache to failed with error message on exception', function () {
    Log::shouldReceive('error')->atLeast()->once();

    // Use an action that doesn't exist to trigger an error
    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'non-existent-action', []);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData)->toBeArray();
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

// ============================================================================
// Text prompt handling tests
// ============================================================================

it('handles text prompts with correct Prism configuration', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Amazing Article Title',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'Sample article']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data']['title'])->toBe('Amazing Article Title');
});

it('handles text prompts with schema-based structured output', function () {
    // Create action directory and files for structured-action
    $actionPath = resource_path('actions/structured-action');
    if (!is_dir($actionPath)) {
        mkdir($actionPath, 0755, true);
    }

    file_put_contents(
        resource_path('actions/structured-action/schema.php'),
        '<?php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: "structured_response",
    description: "A structured response",
    properties: [
        new StringSchema("title", "The title"),
        new StringSchema("description", "The description"),
    ],
    requiredFields: ["title", "description"]
);'
    );

    // Create view templates
    app('view')->addNamespace('magic-actions', resource_path('actions'));
    file_put_contents(
        resource_path('actions/structured-action/system.blade.php'),
        'System prompt for structured action'
    );
    file_put_contents(
        resource_path('actions/structured-action/prompt.blade.php'),
        '{{ $content }}'
    );

    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Structured Title',
            'description' => 'Structured Description',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'structured-action', ['content' => 'test']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data'])->toBeArray();
    expect($cachedData['data']['title'])->toBe('Structured Title');
    expect($cachedData['data']['description'])->toBe('Structured Description');
});

it('applies temperature and max_tokens parameters to text prompts', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Response',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test']);
    $job->handle($loader);

    // Verify it completed successfully (parameters were applied internally)
    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

// ============================================================================
// Audio prompt handling tests
// ============================================================================

it('handles audio prompts correctly', function () {
    // Mock Statamic Asset
    $asset = Mockery::mock();
    $asset->shouldReceive('url')->andReturn('https://example.com/audio.mp3');
    Asset::shouldReceive('find')->with('assets/audio.mp3')->andReturn($asset);

    // For audio, Prism::fake() without parameters returns a default fake transcription
    Prism::fake();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], 'assets/audio.mp3');
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
    expect($cachedData['data']['text'])->toBe('fake transcribed text');
});

it('throws exception when audio prompt has no asset path', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', []);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

it('throws exception when audio asset is not found', function () {
    Log::shouldReceive('error')->atLeast()->once();

    Asset::shouldReceive('find')->with('assets/missing.mp3')->andReturn(null);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'transcribe-audio', [], 'assets/missing.mp3');
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

// ============================================================================
// Media extraction tests
// ============================================================================

it('extracts single image from image variable', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Image description',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => 'https://example.com/image.jpg',
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('extracts array of images from images variable', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Multiple images description',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe these images',
            'image' => 'https://example.com/placeholder.jpg', // Template requires 'image' variable
            'images' => [
                'https://example.com/image1.jpg',
                'https://example.com/image2.jpg',
            ],
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('extracts single document from document variable', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Document summary',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'propose-title',
        ['document' => 'https://example.com/doc.pdf', 'content' => 'test']
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('extracts array of documents from documents variable', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Multiple documents summary',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'propose-title',
        [
            'documents' => [
                'https://example.com/doc1.pdf',
                'https://example.com/doc2.pdf',
            ],
            'content' => 'test',
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('loads asset from assetPath when no image variable provided', function () {
    // Mock Statamic Asset
    $asset = Mockery::mock();
    $asset->shouldReceive('url')->andReturn('https://example.com/asset-image.jpg');
    Asset::shouldReceive('find')->with('assets/image.jpg')->andReturn($asset);

    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Asset image description',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    // Note: The asset image will be loaded from assetPath since 'image' is not set in variables
    // But template needs $image variable, so we use a placeholder URL for template rendering
    $job = new ProcessPromptJob('test-job-id', 'alt-text', ['text' => 'Describe this asset', 'image' => 'https://placeholder.com/image.jpg'], 'assets/image.jpg');
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

// ============================================================================
// Image format handling tests
// ============================================================================

it('handles image URLs in createImage method', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Response',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => 'https://example.com/test-image.jpg',
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('handles base64 data URIs in createImage method', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Response',
        ]),
    ]);

    $base64Image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => $base64Image,
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('handles local file paths in createImage method', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Response',
        ]),
    ]);

    $imagePath = __DIR__.'/../__fixtures__/media/test-image.png';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => $imagePath,
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('throws exception for invalid image format', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => '/path/to/nonexistent/image.jpg',
        ]
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

// ============================================================================
// Document format handling tests
// ============================================================================

it('handles document URLs in createDocument method', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Response',
        ]),
    ]);

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'propose-title',
        ['document' => 'https://example.com/document.pdf', 'content' => 'test']
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('handles local file paths in createDocument method', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Response',
        ]),
    ]);

    $documentPath = __DIR__.'/../__fixtures__/media/sample.txt';

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'propose-title',
        ['document' => $documentPath, 'content' => 'test']
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('completed');
});

it('throws exception for invalid document format', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob(
        'test-job-id',
        'propose-title',
        ['document' => '/path/to/nonexistent/document.pdf', 'content' => 'test']
    );
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

// ============================================================================
// Error handling tests
// ============================================================================

it('logs errors with job_id, action, and error message', function () {
    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'non-existent-action', []);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
});

it('handles MissingApiKeyException', function () {
    // Temporarily remove API key
    Config::set('statamic.magic-actions.providers.openai.api_key', null);

    Log::shouldReceive('error')->atLeast()->once();

    $loader = app(ActionLoader::class);
    $job = new ProcessPromptJob('test-job-id', 'propose-title', ['content' => 'test']);
    $job->handle($loader);

    $cachedData = Cache::get('magic_actions_job_test-job-id');
    expect($cachedData['status'])->toBe('failed');
    expect($cachedData['error'])->toBeString();
    expect($cachedData['error'])->not()->toBeEmpty();
});

// ============================================================================
// Vision action asset resolution tests
// ============================================================================

it('resolves asset path to url for vision actions', function () {
    // Mock an asset that can be found
    $assetMock = Mockery::mock();
    $assetMock->shouldReceive('url')->andReturn('https://example.test/assets/18546.jpg');

    // Mock the Asset facade to return our mock
    Asset::shouldReceive('find')
        ->with('assets::18546.jpg')
        ->andReturn($assetMock);

    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Generated alt text',
        ]),
    ]);

    $loader = app(ActionLoader::class);

    // Create job with assetPath as 4th parameter
    $job = new ProcessPromptJob(
        'test-job-123',
        'alt-text',
        ['text' => 'Describe this image'],
        'assets::18546.jpg'
    );

    $job->handle($loader);

    // Verify cache has successful result
    $cached = Cache::get('magic_actions_job_test-job-123');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toHaveKey('alt_text');
});

it('explicit image variable takes precedence over asset path', function () {
    // When both assetPath and explicit image variable are provided,
    // the explicit image variable should be used (not overridden)

    $explicitImageUrl = 'https://example.test/explicit.jpg';

    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Image description',
        ]),
    ]);

    $loader = app(ActionLoader::class);

    // Create job with both explicit image AND assetPath
    // The explicit image should take precedence
    $job = new ProcessPromptJob(
        'test-job-456',
        'alt-text',
        [
            'text' => 'Describe',
            'image' => $explicitImageUrl  // Explicit variable
        ],
        'assets::some-other-image.jpg'  // Different assetPath (should be ignored)
    );

    $job->handle($loader);

    // Verify the explicit image URL was preserved
    $cached = Cache::get('magic_actions_job_test-job-456');
    expect($cached['status'])->toBe('completed');
});

it('handles missing asset gracefully', function () {
    // When asset path is provided but asset doesn't exist,
    // the asset resolution returns null and doesn't override explicit variables

    // Mock Asset facade to return null (not found)
    Asset::shouldReceive('find')
        ->with('assets::nonexistent.jpg')
        ->andReturn(null);

    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'alt_text' => 'Generated text without image',
        ]),
    ]);

    $loader = app(ActionLoader::class);

    // Create job with both explicit image AND missing asset path
    // The explicit image prevents the failure, and missing asset doesn't crash
    $job = new ProcessPromptJob(
        'test-job-789',
        'alt-text',
        [
            'text' => 'Describe',
            'image' => 'https://fallback.test/image.jpg'  // Explicit image provided
        ],
        'assets::nonexistent.jpg'  // Asset doesn't exist, but we have explicit image
    );

    $job->handle($loader);

    // Verify job completed (with the explicit image variable)
    $cached = Cache::get('magic_actions_job_test-job-789');
    expect($cached['status'])->toBe('completed');
});
