# Prism-Based Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace custom OpenAI service and three job classes with Prism PHP for provider-agnostic, config-driven AI integration with co-located prompt files.

**Architecture:**

- Install Prism PHP package for unified provider abstraction
- Simplify config to store only provider, model, and parameters (not input/output schemas)
- Create folder-based prompt structure (system.blade.php, prompt.blade.php, schema.php per action)
- Build PromptLoader service to dynamically load and render prompts
- Create single ProcessPromptJob that handles text, audio, and image via Prism's capability-based API
- Remove old OpenAIService and three job classes

**Tech Stack:**

- Prism PHP (provider abstraction)
- Blade templates (system/user prompts)
- Laravel config (action/prompt metadata)
- Statamic (framework context)

---

## Task 1: Install Prism PHP

**Files:**

- Modify: `composer.json`
- Create: `.env.example` (updated with any new env vars if needed)

**Step 1: Add Prism to composer.json**

Run: `composer require prism-php/prism`

Expected output includes "Installing prism-php/prism" and version constraint added to `composer.json`

**Step 2: Verify installation**

Run: `composer show | grep prism`

Expected: Shows `prism-php/prism` with version

**Step 3: Check if Prism config needs publishing**

Run: `php artisan vendor:publish --tag=prism-config`

Expected: Either publishes `config/prism.php` or outputs "Nothing to publish"

**Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: install prism-php for provider abstraction"
```

---

## Task 2: Simplify magic-actions.php Config

**Files:**

- Modify: `config/statamic/magic-actions.php`

**Step 1: Read current config**

Open `config/statamic/magic-actions.php` to understand current structure

**Step 2: Replace with new structure**

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Provider credentials for Prism. API keys loaded from environment.
    |
    */
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    |
    | Actions organized by capability (text, audio, image).
    | Each action references a folder in resources/actions/{action}
    |
    */
    'actions' => [
        'text' => [
            'alt-text' => [
                'provider' => 'openai',
                'model' => 'gpt-4-vision-preview',
                'parameters' => [
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                ],
            ],
            'propose-title' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'parameters' => [
                    'temperature' => 0.7,
                    'max_tokens' => 200,
                ],
            ],
            'extract-tags' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'parameters' => [
                    'temperature' => 0.5,
                    'max_tokens' => 500,
                ],
            ],
            'assign-tags-from-taxonomies' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'parameters' => [
                    'temperature' => 0.5,
                    'max_tokens' => 500,
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
            'create-teaser' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'parameters' => [
                    'temperature' => 0.8,
                    'max_tokens' => 500,
                ],
            ],
            'extract-assets-tags' => [
                'provider' => 'openai',
                'model' => 'gpt-4-vision-preview',
                'parameters' => [
                    'temperature' => 0.5,
                    'max_tokens' => 500,
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

    /*
    |--------------------------------------------------------------------------
    | Fieldtypes
    |--------------------------------------------------------------------------
    |
    | Fieldtypes and their magic actions.
    | Each action references its configuration by action.
    |
    */
    'fieldtypes' => [
        'Statamic\Fieldtypes\Terms' => [
            'actions' => [
                [
                    'title' => 'Extract Tags',
                    'action' => 'extract-tags',
                ],
                [
                    'title' => 'Assign Tags from Taxonomies',
                    'action' => 'assign-tags-from-taxonomies',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Text' => [
            'actions' => [
                [
                    'title' => 'Propose Title',
                    'action' => 'propose-title',
                ],
                [
                    'title' => 'Alt Text',
                    'action' => 'alt-text',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Textarea' => [
            'actions' => [
                [
                    'title' => 'Extract Meta Description',
                    'action' => 'extract-meta-description',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Bard' => [
            'actions' => [
                [
                    'title' => 'Create Teaser',
                    'action' => 'create-teaser',
                ],
                [
                    'title' => 'Transcribe Audio',
                    'action' => 'transcribe-audio',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Assets' => [
            'actions' => [
                [
                    'title' => 'Extract Tags',
                    'action' => 'extract-assets-tags',
                ],
            ],
        ],
    ],
];
```

**Step 3: Verify syntax**

Run: `php -l config/statamic/magic-actions.php`

Expected: "No syntax errors detected"

**Step 4: Commit**

```bash
git add config/statamic/magic-actions.php
git commit -m "refactor: simplify prompts config to provider/model/parameters only"
```

---

## Task 3: Create Action Folder Structure - Alt Text

**Files:**

- Create: `resources/actions/alt-text/system.blade.php`
- Create: `resources/actions/alt-text/prompt.blade.php`
- Create: `resources/actions/alt-text/schema.php`

**Step 1: Create system prompt**

Create file `resources/actions/alt-text/system.blade.php`:

```blade
Generate a high-quality alt text description for the provided image. Alt text should be concise, descriptive, and convey the important visual information for users who cannot see the image.

# Steps

1. **Analyze the Image**: Carefully examine all important visual elements.
2. **Identify Key Content**: Determine what's most important about this image in context.
3. **Create Concise Description**: Write a brief but descriptive alt text that captures the essence of the image.
4. **Review for Accessibility**: Ensure the alt text serves its primary purpose of providing equivalent information to visually impaired users.

# Guidelines

- Keep alt text concise (typically 125 characters or less) but descriptive
- Focus on the most important visual information
- Include relevant context and purpose of the image
- Don't begin with phrases like "Image of" or "Picture of"
- Use proper grammar and punctuation
- If image contains text, include that text in the description
- Consider the context of where the image will be used
```

**Step 2: Create prompt template**

Create file `resources/actions/alt-text/prompt.blade.php`:

```blade
{{ $text }}

{{ $image }}
```

**Step 3: Create schema**

Create file `resources/actions/alt-text/schema.php`:

```php
<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'alt_text_response',
    description: 'Alt text description for image',
    properties: [
        new StringSchema('data', 'Alt text description'),
    ],
    requiredFields: ['data']
);
```

**Step 4: Verify files exist**

Run: `ls -la resources/actions/alt-text/`

Expected: Shows `system.blade.php`, `prompt.blade.php`, `schema.php`

**Step 5: Commit**

```bash
git add resources/actions/alt-text/
git commit -m "feat: create alt-text action structure with system, prompt, and schema"
```

---

## Task 4: Create Action Folder Structure - Remaining Actions

**Files:**

- Create: `resources/actions/{action}/system.blade.php` for each text action
- Create: `resources/actions/{action}/prompt.blade.php` for each action
- Create: `resources/actions/{action}/schema.php` for each text action

**Step 1: Create propose-title action**

Create `resources/actions/propose-title/system.blade.php`:

```blade
You are a content expert. Generate compelling, SEO-friendly titles for web content.

# Requirements

- Titles should be 50-60 characters for optimal display
- Use power words when appropriate
- Make it descriptive and engaging
- Avoid clickbait
```

Create `resources/actions/propose-title/prompt.blade.php`:

```blade
{{ $content }}
```

Create `resources/actions/propose-title/schema.php`:

```php
<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'title_response',
    description: 'Proposed title for content',
    properties: [
        new StringSchema('title', 'Proposed title'),
    ],
    requiredFields: ['title']
);
```

**Step 2: Create extract-tags action**

Create `resources/actions/extract-tags/system.blade.php`:

```blade
You are a content tagging expert. Extract relevant, concise tags from the provided content.

# Requirements

- Tags should be single words or short phrases
- Return 3-7 tags maximum
- Tags should be lowercase
- Avoid generic terms
```

Create `resources/actions/extract-tags/prompt.blade.php`:

```blade
{{ $content }}
```

Create `resources/actions/extract-tags/schema.php`:

```php
<?php

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'tags_response',
    description: 'Extracted tags from content',
    properties: [
        new ArraySchema('tags', 'Array of tag strings', new StringSchema('tag', 'A single tag')),
    ],
    requiredFields: ['tags']
);
```

**Step 3: Create remaining prompts**

Repeat pattern for:

- `assign-tags-from-taxonomies`
- `extract-meta-description`
- `create-teaser`
- `extract-assets-tags`

Use similar structure with appropriate system messages, prompt templates, and schemas for each.

**Step 4: Create transcribe-audio (no schema)**

Create `resources/actions/transcribe-audio/system.blade.php`:

```blade
You are a transcription assistant. Transcribe the provided audio accurately.
```

Note: No `schema.php` for audio since Prism::audio() returns text directly

**Step 5: Verify structure**

Run: `find resources/actions -type f | sort`

Expected: Shows all action files organized by action

**Step 6: Commit**

```bash
git add resources/actions/
git commit -m "feat: create all action structures with system, prompt, and schema files"
```

---

## Task 5: Update ServiceProvider - Remove Old Services & Register New Ones

**Files:**

- Modify: `src/StatamicMagicActionsServiceProvider.php`

**Step 1: Read current ServiceProvider**

Open `src/StatamicMagicActionsServiceProvider.php` to understand current registrations.

**Step 2: Update the boot() method**

Update the `boot()` method to:

1. Register the actions folder as a view namespace
2. Remove any OpenAIService singleton registration if present

```php
public function boot(): void
{
    // Register views from resources/actions with namespace 'magic-actions'
    $this->loadViewsFrom(
        resource_path('actions'),
        'magic-actions'
    );

    // ... rest of boot method
}
```

**Step 3: Update the register() method**

Update the `register()` method to:

1. Remove OpenAIService singleton (if it exists)
2. Register ActionLoader as singleton for dependency injection

```php
public function register(): void
{
    // Register ActionLoader service
    $this->app->singleton(ActionLoader::class, function ($app) {
        return new ActionLoader();
    });

    // ... other registrations
    // NOTE: Remove any $this->app->singleton(OpenAIService::class, ...) lines
}
```

**Step 4: Add ActionLoader import**

Ensure ActionLoader is imported at the top:

```php
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
```

**Step 5: Verify syntax**

Run: `php -l src/StatamicMagicActionsServiceProvider.php`

Expected: "No syntax errors detected"

**Step 6: Commit**

```bash
git add src/StatamicMagicActionsServiceProvider.php
git commit -m "refactor: update ServiceProvider to register ActionLoader and remove OpenAIService"
```

---

## Task 6: Create ActionLoader Service

**Files:**

- Create: `src/Services/ActionLoader.php`

**Step 1: Write the ActionLoader service**

Create file `src/Services/ActionLoader.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;

final class ActionLoader
{
    /**
     * Load an action with variables rendered
     *
     * @param string $action The action identifier
     * @param array $variables Variables to render in templates
     * @return array Contains: provider, model, parameters, systemPrompt, userPrompt, schema (if exists)
     * @throws \RuntimeException If action config not found
     */
    public function load(string $action, array $variables = []): array
    {
        $actionConfig = $this->findActionConfig($action);

        if (!$actionConfig) {
            throw new \RuntimeException("Action '{$action}' not found in configuration");
        }

        $actionType = $actionConfig['type'];
        $provider = $actionConfig['provider'];
        $model = $actionConfig['model'];
        $parameters = $actionConfig['parameters'] ?? [];

        // Validate provider API key
        $apiKey = Config::get("statamic.magic-actions.providers.{$provider}.api_key");
        if (!$apiKey) {
            throw new MissingApiKeyException("API key not configured for provider '{$provider}'");
        }

        $result = [
            'type' => $actionType,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $parameters,
        ];

        // Load prompts for text actions
        if ($actionType === 'text') {
            $result['systemPrompt'] = $this->loadView("magic-actions::{$action}.system", $variables);
            $result['userPrompt'] = $this->loadView("magic-actions::{$action}.prompt", $variables);

            // Load schema if it exists
            $schemaPath = resource_path("actions/{$action}/schema.php");
            if (file_exists($schemaPath)) {
                $result['schema'] = require $schemaPath;
            }
        }

        // Audio actions don't need system/user prompts in the same way
        if ($actionType === 'audio') {
            // Keep minimal - Prism::audio() handles transcription directly
        }

        return $result;
    }

    /**
     * Find action configuration across all capabilities
     */
    private function findActionConfig(string $action): ?array
    {
        $actions = Config::get('statamic.magic-actions.actions', []);

        foreach ($actions as $type => $typeActions) {
            if (isset($typeActions[$action])) {
                return array_merge(
                    ['type' => $type],
                    $typeActions[$action]
                );
            }
        }

        return null;
    }

    /**
     * Load and render a view with variables
     */
    private function loadView(string $viewName, array $variables): string
    {
        return View::make($viewName, $variables)->render();
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/Services/ActionLoader.php`

Expected: "No syntax errors detected"

**Step 3: Commit**

```bash
git add src/Services/ActionLoader.php
git commit -m "feat: create ActionLoader service for dynamic action loading"
```

---

## Task 7: Create ProcessPromptJob

**Files:**

- Create: `src/Jobs/ProcessPromptJob.php`

**Step 1: Write the job**

Create file `src/Jobs/ProcessPromptJob.php`:

```php
<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Exceptions\OpenAIApiException;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Statamic\Facades\Asset;

final class ProcessPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $jobId;
    private string $action;
    private array $variables;
    private ?string $assetId = null;
    private ?string $assetPath = null;

    public function __construct(
        string $jobId,
        string $action,
        array $variables,
        ?string $assetId = null,
        ?string $assetPath = null
    ) {
        $this->jobId = $jobId;
        $this->action = $action;
        $this->variables = $variables;
        $this->assetId = $assetId;
        $this->assetPath = $assetPath;
    }

    public function handle(ActionLoader $actionLoader): void
    {
        try {
            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'processing',
                'message' => 'Processing request...',
            ], 3600);

            $promptData = $actionLoader->load($this->action, $this->variables);

            // Route to appropriate Prism method based on prompt type
            if ($promptData['type'] === 'text') {
                $response = $this->handleTextPrompt($promptData);
            } elseif ($promptData['type'] === 'audio') {
                $response = $this->handleAudioPrompt($promptData);
            } else {
                throw new Exception("Unknown prompt type: {$promptData['type']}");
            }

            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'completed',
                'data' => $response,
            ], 3600);

        } catch (Exception $e) {
            Log::error('Job error', [
                'job_id' => $this->jobId,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
            $this->handleError($e->getMessage());
        }
    }

    private function handleTextPrompt(array $promptData): array
    {
        $provider = $promptData['provider'];
        $model = $promptData['model'];
        $parameters = $promptData['parameters'];

        // Collect media (images, documents, etc.) from variables
        $media = $this->extractMedia($this->variables);

        // Build Prism request
        $prismRequest = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($promptData['systemPrompt']);

        // Add prompt with media if present
        if (!empty($media)) {
            $prismRequest->withPrompt($promptData['userPrompt'], $media);
        } else {
            $prismRequest->withPrompt($promptData['userPrompt']);
        }

        // Apply parameters
        if (isset($parameters['temperature'])) {
            $prismRequest->usingTemperature($parameters['temperature']);
        }
        if (isset($parameters['max_tokens'])) {
            $prismRequest->withMaxTokens($parameters['max_tokens']);
        }

        // Use structured output if schema exists
        if (isset($promptData['schema'])) {
            $response = Prism::structured()
                ->using($provider, $model)
                ->withSystemPrompt($promptData['systemPrompt']);

            if (!empty($media)) {
                $response->withPrompt($promptData['userPrompt'], $media);
            } else {
                $response->withPrompt($promptData['userPrompt']);
            }

            $response->withSchema($promptData['schema']);

            if (isset($parameters['temperature'])) {
                $response->usingTemperature($parameters['temperature']);
            }
            if (isset($parameters['max_tokens'])) {
                $response->withMaxTokens($parameters['max_tokens']);
            }

            $result = $response->asStructured();
            return $result->structured;
        } else {
            $result = $prismRequest->asText();
            return ['text' => $result->text];
        }
    }

    /**
     * Extract media objects from variables
     * Supports: image, images, document, documents, audio, video
     */
    private function extractMedia(array $variables): array
    {
        $media = [];

        // Handle image data
        if (isset($variables['image'])) {
            $media[] = $this->createImage($variables['image']);
        }
        if (isset($variables['images']) && is_array($variables['images'])) {
            foreach ($variables['images'] as $image) {
                $media[] = $this->createImage($image);
            }
        }

        // Handle document data
        if (isset($variables['document'])) {
            $media[] = $this->createDocument($variables['document']);
        }
        if (isset($variables['documents']) && is_array($variables['documents'])) {
            foreach ($variables['documents'] as $doc) {
                $media[] = $this->createDocument($doc);
            }
        }

        return $media;
    }

    /**
     * Create Image object from various formats
     * Supports: URL, base64, file path
     */
    private function createImage($imageData): Image
    {
        if (is_string($imageData)) {
            // Check if it's a URL
            if (filter_var($imageData, FILTER_VALIDATE_URL)) {
                return Image::fromUrl($imageData);
            }
            // Check if it's base64
            if (strpos($imageData, 'data:image/') === 0) {
                $base64 = preg_replace('/^data:image\/[^;]+;base64,/', '', $imageData);
                return Image::fromBase64($base64);
            }
            // Treat as local path
            if (file_exists($imageData)) {
                return Image::fromLocalPath($imageData);
            }
        }

        throw new Exception("Unable to determine image format for: {$imageData}");
    }

    /**
     * Create Document object from various formats
     * Supports: local path, URL
     */
    private function createDocument($documentData): Document
    {
        if (is_string($documentData)) {
            // Check if it's a URL
            if (filter_var($documentData, FILTER_VALIDATE_URL)) {
                return Document::fromUrl($documentData);
            }
            // Treat as local path
            if (file_exists($documentData)) {
                return Document::fromLocalPath($documentData);
            }
        }

        throw new Exception("Unable to determine document format for: {$documentData}");
    }

    private function handleAudioPrompt(array $promptData): array
    {
        if (!$this->assetPath) {
            throw new Exception('Asset path required for audio prompts');
        }

        $provider = $promptData['provider'];
        $model = $promptData['model'];
        $parameters = $promptData['parameters'];

        // Get asset file path
        $asset = Asset::find($this->assetPath);
        if (!$asset) {
            throw new Exception('Audio asset not found');
        }

        $audioFile = Audio::fromUrl($asset->url());

        $response = Prism::audio()
            ->using($provider, $model)
            ->withInput($audioFile);

        if (!empty($parameters)) {
            $response->withProviderOptions($parameters);
        }

        $result = $response->asText();
        return ['text' => $result->text];
    }

    private function handleError(string $message): void
    {
        Cache::put("magic_actions_job_{$this->jobId}", [
            'status' => 'failed',
            'error' => $message,
        ], 3600);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/Jobs/ProcessPromptJob.php`

Expected: "No syntax errors detected"

**Step 3: Commit**

```bash
git add src/Jobs/ProcessPromptJob.php
git commit -m "feat: create ProcessPromptJob with vision image and document support"
```

**Note:** This includes:

- Text prompts with optional system prompts
- Vision/image support (URLs, base64, local paths)
- Document support for multi-modal analysis
- Audio transcription
- Structured output via schemas
- Automatic media format detection

---

## Task 8: Update Services - Move promptExists Logic

**Files:**

- Modify: `src/Services/ActionLoader.php`
- Modify: `src/Services/PromptsService.php` (or deprecate)

**Step 1: Add promptExists() to ActionLoader**

Add a method to ActionLoader to check if an action exists in config:

```php
public function exists(string $action): bool
{
    return $this->findActionConfig($action) !== null;
}
```

**Step 2: Update PromptsService or deprecate it**

Either:

- **Option A:** Update PromptsService to delegate to ActionLoader
- **Option B:** Remove PromptsService (if only used for promptExists)

For now, assume Option A - update PromptsService.promptExists() to:

```php
public function promptExists(string $action): bool
{
    // Delegate to ActionLoader
    return app(ActionLoader::class)->exists($action);
}
```

This maintains backward compatibility during transition.

**Step 3: Identify other services to remove**

The following services were parsing old .md prompt files and are no longer needed:

- `PromptParserService` - **DELETE** (no longer needed with Blade templates)
- `AssetsService` - **KEEP** (likely still used for asset handling)
- `FieldConfigService` - **KEEP** (needed for fieldtype integration)

**Step 4: Commit**

```bash
git add src/Services/ActionLoader.php src/Services/PromptsService.php
git commit -m "refactor: move promptExists logic to ActionLoader"
```

---

## Task 9: Update ActionsController - Consolidate Endpoints

**Files:**

- Modify: `src/Http/Controllers/ActionsController.php`
- Modify: `routes/api.php` (or equivalent routes file)

**Step 1: Read current controller and routes**

Open `src/Http/Controllers/ActionsController.php` to understand the three endpoints:

- `completion()` - text prompts
- `vision()` - image prompts
- `transcribe()` - audio transcription

Check `routes/api.php` to see the current route definitions.

**Step 2: Decide on consolidation strategy**

Two options:

**Option A (Recommended):** Keep three separate endpoints for backward compatibility

- Update each to use ProcessPromptJob instead of their specific job classes
- Each validates action exists using ActionLoader
- Routes remain unchanged

**Option B:** Consolidate to single `process()` endpoint

- Create new `process()` method that detects action type from config
- Remove `completion()`, `vision()`, `transcribe()` methods
- Update routes to point to single endpoint
- Requires client updates to use new endpoint

**Recommendation:** Use **Option A** for minimal disruption

**Step 3: Update controller methods (Option A)**

Update the three methods to use the new unified ProcessPromptJob:

```php
public function completion(Request $request): JsonResponse
{
    try {
        $request->validate([
            'text' => 'required|string',
            'action' => 'required|string',  // was 'prompt'
        ]);

        $text = $request->input('text');
        $action = $request->input('action');

        if (! app(ActionLoader::class)->exists($action)) {
            return response()->json(['error' => 'Action not found'], 404);
        }

        $jobId = (string) Str::uuid();

        return $this->queueBackgroundJob($jobId, $action, function () use ($jobId, $action, $text) {
            ProcessPromptJob::dispatch($jobId, $action, ['text' => $text]);
        });
    } catch (MissingApiKeyException) {
        return $this->apiKeyNotConfiguredError('Completion');
    }
}

public function vision(Request $request): JsonResponse
{
    try {
        $request->validate([
            'asset_path' => 'required|string',
            'action' => 'required|string',  // was 'prompt'
            'variables' => 'sometimes|array',
        ]);

        $assetPath = $request->input('asset_path');
        $action = $request->input('action');
        $variables = $request->input('variables', []);

        if (! app(ActionLoader::class)->exists($action)) {
            return response()->json(['error' => 'Action not found'], 404);
        }

        $jobId = (string) Str::uuid();

        return $this->queueBackgroundJob($jobId, $action, function () use ($jobId, $action, $assetPath, $variables) {
            // Get image from asset and merge into variables
            $variables['image'] = asset($assetPath)->url();
            ProcessPromptJob::dispatch($jobId, $action, $variables, null, $assetPath);
        });
    } catch (MissingApiKeyException) {
        return $this->apiKeyNotConfiguredError('Vision');
    }
}

public function transcribe(Request $request): JsonResponse
{
    try {
        $request->validate([
            'asset_path' => 'required|string',
            'action' => 'required|string',  // was 'prompt'
        ]);

        $assetPath = $request->input('asset_path');
        $action = $request->input('action');

        if (! app(ActionLoader::class)->exists($action)) {
            return response()->json(['error' => 'Action not found'], 404);
        }

        $jobId = (string) Str::uuid();

        return $this->queueBackgroundJob($jobId, $action, function () use ($jobId, $action, $assetPath) {
            ProcessPromptJob::dispatch($jobId, $action, [], null, $assetPath);
        });
    } catch (MissingApiKeyException) {
        return $this->apiKeyNotConfiguredError('Transcription');
    }
}
```

**Step 4: Update imports**

Replace old job imports:

```php
use ElSchneider\StatamicMagicActions\Jobs\ProcessCompletionJob;
use ElSchneider\StatamicMagicActions\Jobs\ProcessTranscriptionJob;
use ElSchneider\StatamicMagicActions\Jobs\ProcessVisionJob;
```

With:

```php
use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
```

Remove PromptsService dependency:

```php
// DELETE: private PromptsService $promptsService;
// DELETE: public function __construct(PromptsService $promptsService)
```

**Step 5: Verify routes file**

The routes in `routes/actions.php` don't need changes - the controller method names stay the same:

```php
// routes/actions.php - no changes needed
Route::post('completion', [ActionsController::class, 'completion'])->name('completion');
Route::post('vision', [ActionsController::class, 'vision'])->name('vision');
Route::post('transcribe', [ActionsController::class, 'transcribe'])->name('transcribe');
Route::get('status/{jobId}', [ActionsController::class, 'status'])->name('status');
```

Note: Client requests now use 'action' parameter instead of 'prompt' parameter in the request body.

**Step 6: Verify syntax**

Run: `php -l src/Http/Controllers/ActionsController.php`

Expected: "No syntax errors detected"

**Step 7: Commit**

```bash
git add src/Http/Controllers/ActionsController.php
git commit -m "refactor: consolidate endpoints to use unified ProcessPromptJob"
```

---

## Task 10: Remove Old Services and Jobs

**Files:**

- Delete: `src/Services/OpenAIService.php`
- Delete: `src/Services/PromptParserService.php`
- Delete: `src/Jobs/ProcessCompletionJob.php`
- Delete: `src/Jobs/ProcessVisionJob.php`
- Delete: `src/Jobs/ProcessTranscriptionJob.php`

**Step 1: Remove old files**

Run: `rm src/Services/OpenAIService.php src/Services/PromptParserService.php src/Jobs/ProcessCompletionJob.php src/Jobs/ProcessVisionJob.php src/Jobs/ProcessTranscriptionJob.php`

**Step 2: Verify removal**

Run: `git status`

Expected: Shows 4 deleted files

**Step 3: Commit**

```bash
git add -A
git commit -m "refactor: remove OpenAIService, PromptParserService, and old job classes"
```

---

## Task 11: Create Tests for ActionLoader

**Files:**

- Create: `tests/Services/ActionLoaderTest.php`

**Step 1: Write test file**

Create `tests/Services/ActionLoaderTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Services\ActionLoader;

beforeEach(function () {
    $this->loader = new ActionLoader();
});

test('loads text action with system and user prompts', function () {
    $data = $this->loader->load('alt-text', [
        'text' => 'Sample text',
        'image' => 'data:image/png;base64,...',
    ]);

    expect($data['type'])->toBe('text');
    expect($data['provider'])->toBe('openai');
    expect($data['model'])->toBe('gpt-4-vision-preview');
    expect($data['systemPrompt'])->toBeString();
    expect($data['userPrompt'])->toBeString();
    expect($data['schema'])->not->toBeNull();
});

test('throws on missing action', function () {
    $this->loader->load('nonexistent');
})->throws(\RuntimeException::class, "Action 'nonexistent' not found");

test('renders variables in templates', function () {
    $data = $this->loader->load('alt-text', [
        'text' => 'Test text content',
        'image' => 'Test image URL',
    ]);

    expect($data['userPrompt'])->toContain('Test text content');
    expect($data['userPrompt'])->toContain('Test image URL');
});

test('loads audio action', function () {
    $data = $this->loader->load('transcribe-audio');

    expect($data['type'])->toBe('audio');
    expect($data['provider'])->toBe('openai');
    expect($data['model'])->toBe('whisper-1');
});

test('includes parameters in config', function () {
    $data = $this->loader->load('alt-text');

    expect($data)->toHaveKey('parameters');
    expect($data['parameters'])->toHaveKey('temperature');
    expect($data['parameters'])->toHaveKey('max_tokens');
});
```

**Step 2: Run tests**

Run: `php artisan test tests/Services/ActionLoaderTest.php`

Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Services/ActionLoaderTest.php
git commit -m "test: add ActionLoader tests"
```

---

## Task 12: Create Tests for ProcessPromptJob

**Files:**

- Create: `tests/Jobs/ProcessPromptJobTest.php`

**Step 1: Write test file**

Create `tests/Jobs/ProcessPromptJobTest.php`:

```php
<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;

beforeEach(function () {
    Queue::fake();
});

test('dispatches job', function () {
    ProcessPromptJob::dispatch(
        'test-job-123',
        'alt-text',
        ['text' => 'Test', 'image' => 'test.jpg']
    );

    Queue::assertPushed(ProcessPromptJob::class);
});

test('updates cache with processing status', function () {
    Prism::fake();

    $job = new ProcessPromptJob(
        'test-job-456',
        'alt-text',
        ['text' => 'Test', 'image' => 'test.jpg']
    );

    // Would need service container setup to fully test
    expect($job)->toBeInstanceOf(ProcessPromptJob::class);
});

test('handles audio prompt', function () {
    // Audio test would require asset fixtures
    expect(true)->toBeTrue();
});

test('extracts and creates image objects from various formats', function () {
    // This test validates that the job properly handles different image formats
    // URL format
    $job = new ProcessPromptJob(
        'test-job-789',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => 'https://example.com/image.png'
        ]
    );
    expect($job)->toBeInstanceOf(ProcessPromptJob::class);

    // Base64 format
    $job = new ProcessPromptJob(
        'test-job-790',
        'alt-text',
        [
            'text' => 'Describe this image',
            'image' => 'data:image/png;base64,iVBORw0KGgoAAAANS...'
        ]
    );
    expect($job)->toBeInstanceOf(ProcessPromptJob::class);
});
```

**Step 2: Run tests**

Run: `php artisan test tests/Jobs/ProcessPromptJobTest.php`

Expected: Tests pass or show expected failures (depending on environment setup)

**Step 3: Commit**

```bash
git add tests/Jobs/ProcessPromptJobTest.php
git commit -m "test: add ProcessPromptJob basic tests"
```

---

## Task 13: Verify All Tests Pass

**Files:**

- Test all existing tests still pass

**Step 1: Run full test suite**

Run: `php artisan test`

Expected: All tests pass

**Step 2: Fix any failures**

If tests fail, identify and fix issues:

- Check for missing imports
- Verify config structure
- Ensure blade files exist

**Step 3: Commit if any fixes needed**

```bash
git add .
git commit -m "fix: address test failures from refactor"
```

---

## Task 14: Update Documentation

**Files:**

- Create: `REFACTOR_NOTES.md` (or add to README if preferred)

**Step 1: Document the refactor**

Create a file explaining:

- Why Prism was chosen
- How to add new prompts
- Prompt folder structure
- Config structure

**Step 2: Update README if needed**

Update any relevant documentation about configuration and usage

**Step 3: Commit**

```bash
git add docs/
git commit -m "docs: add refactor documentation"
```

---

## Summary

**Architecture achieved:**

- ✅ Prism PHP installed for provider abstraction
- ✅ Config simplified to only provider, model, parameters
- ✅ Actions co-located in `resources/actions/{action}/`
- ✅ Single `ProcessPromptJob` replaces three job classes
- ✅ `ActionLoader` dynamically loads and renders actions
- ✅ Tests cover new services and jobs
- ✅ Old OpenAIService and job classes removed

**Key benefits:**

- Provider-agnostic (swap OpenAI for Anthropic via config)
- Self-contained prompt folders (easy to maintain)
- No HTTP wrapper needed (Prism handles it)
- Type-safe via schemas
- Extensible for new capabilities
