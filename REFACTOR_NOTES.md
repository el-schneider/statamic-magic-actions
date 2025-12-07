# Prism Refactor Notes

This document outlines the architectural refactor of Statamic Magic Actions from a custom OpenAI-only implementation to a provider-agnostic system powered by [Prism PHP](https://github.com/echolabsdev/prism).

## Table of Contents

- [Why Prism?](#why-prism)
- [Architecture Changes](#architecture-changes)
- [Configuration Structure](#configuration-structure)
- [Prompt Folder Structure](#prompt-folder-structure)
- [How to Add New Actions](#how-to-add-new-actions)
- [Migration Guide](#migration-guide)

## Why Prism?

Prism PHP provides a unified abstraction layer for multiple AI providers, allowing us to:

### Benefits Over OpenAI-Only Service

1. **Provider Flexibility**: Support for OpenAI, Anthropic, and other providers without code changes
2. **Simplified API**: Prism handles provider-specific quirks and provides a consistent interface
3. **Advanced Features**: Built-in support for structured outputs, vision, audio transcription, and document analysis
4. **Type Safety**: Strong typing and schema validation using Prism's schema system
5. **Less Maintenance**: Prism handles API updates and provider-specific implementation details
6. **Better Testing**: Easier to mock and test without maintaining provider-specific HTTP clients

### What Prism Handles

- Provider authentication and API communication
- Request formatting for different providers
- Response parsing and error handling
- Media handling (images, documents, audio)
- Structured output with schema validation
- Rate limiting and retry logic

## Architecture Changes

### What Was Replaced

#### Old Services (Removed)

```
src/Services/OpenAIService.php          # Direct OpenAI API client
src/Services/PromptParserService.php    # Manual prompt template parsing
```

**Old OpenAIService** handled:

- Direct HTTP calls to OpenAI API
- Manual message formatting
- Vision API requests with image URLs
- Audio transcription via Whisper API
- Error handling and response parsing

**Old PromptParserService** handled:

- Manual Blade template rendering
- Variable interpolation
- Prompt loading from config files

#### Old Jobs (Removed)

```
src/Jobs/ProcessCompletionJob.php       # Text-only processing
src/Jobs/ProcessVisionJob.php           # Vision-only processing
src/Jobs/ProcessTranscriptionJob.php    # Audio-only processing
```

Each job handled a specific capability type with duplicated logic for job queuing, caching, and error handling.

### What's New

#### New Services

```
src/Services/ActionLoader.php           # Unified action loading and validation
```

**ActionLoader** provides:

- Dynamic action discovery from configuration
- Blade template rendering for system and user prompts
- Schema loading for structured outputs
- Provider validation
- Type-aware action loading (text, audio, image)

#### Unified Job

```
src/Jobs/ProcessPromptJob.php           # Single job for all capability types
```

**ProcessPromptJob** handles:

- All capability types (text, vision, audio)
- Dynamic routing based on action type
- Media extraction from variables
- Prism request building
- Structured output handling
- Asset path resolution

#### Configuration Changes

**Before:**

```php
'prompts' => [
    'propose-title' => [
        'system' => 'You are a title generator...',
        'user' => 'Generate a title for: {text}',
        'model' => 'gpt-4',
        'provider' => 'openai',
    ],
],
```

**After:**

```php
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
    ],
],
```

Prompts are now co-located with their action in `resources/actions/{action-name}/`.

## Configuration Structure

### Provider Configuration

Provider credentials are defined once and reused across actions:

```php
'providers' => [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],
],
```

### Action Configuration

Actions are organized by capability type and reference co-located prompt folders:

```php
'actions' => [
    // Text-based actions (completion, vision with text output)
    'text' => [
        'propose-title' => [
            'provider' => 'openai',         // Which provider to use
            'model' => 'gpt-4',             // Model identifier
            'parameters' => [
                'temperature' => 0.7,       // Model temperature
                'max_tokens' => 200,        // Maximum tokens in response
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

    // Audio transcription actions
    'audio' => [
        'transcribe-audio' => [
            'provider' => 'openai',
            'model' => 'whisper-1',
            'parameters' => [
                'language' => 'en',         // Whisper-specific parameter
            ],
        ],
    ],
],
```

### Fieldtype Configuration

Fieldtypes map UI actions to backend action handlers:

```php
'fieldtypes' => [
    'Statamic\Fieldtypes\Text' => [
        'actions' => [
            [
                'title' => 'Propose Title',    // UI display name
                'action' => 'propose-title',   // References actions.text.propose-title
            ],
        ],
    ],
],
```

## Prompt Folder Structure

Each action has a dedicated folder in `resources/actions/{action-name}/` containing:

```
resources/actions/
├── propose-title/
│   ├── system.blade.php    # System prompt (required for text actions)
│   ├── prompt.blade.php    # User prompt (required for text actions)
│   └── schema.php          # Optional: for structured output
├── alt-text/
│   ├── system.blade.php
│   ├── prompt.blade.php
│   └── schema.php
└── transcribe-audio/
    # No prompt files needed for audio transcription
```

### File Purposes

#### `system.blade.php`

Defines the AI's role and behavior. Contains instructions, constraints, and output format.

**Example** (`propose-title/system.blade.php`):

```blade
You are a content expert. Generate compelling, SEO-friendly titles for web content.

# Requirements

- Titles should be 50-60 characters for optimal display
- Use power words when appropriate
- Make it descriptive and engaging
- Avoid clickbait
```

#### `prompt.blade.php`

Contains the user request with variable interpolation. Variables are passed from the frontend.

**Example** (`propose-title/prompt.blade.php`):

```blade
{{ $content }}
```

**Available Variables:**

- `$text` - Text content from field
- `$content` - Alias for text
- `$image` - Image URL (for vision actions)
- `$images` - Array of image URLs
- `$document` - Document path
- `$documents` - Array of document paths
- `$asset` - Asset object (automatically resolved from asset_path)
- Custom variables passed from frontend

#### `schema.php`

Defines structured output format using Prism's schema system. When present, the action uses `Prism::structured()` instead of `Prism::text()`.

**Example** (`assign-tags-from-taxonomies/schema.php`):

```php
<?php

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'assigned_tags_response',
    description: 'Tags assigned from available taxonomy',
    properties: [
        new ArraySchema(
            'tags',
            'Array of selected tag strings',
            new StringSchema('tag', 'A single tag from the taxonomy')
        ),
    ],
    requiredFields: ['tags']
);
```

**Available Schema Types:**

- `StringSchema` - String values
- `IntegerSchema` - Integer values
- `BooleanSchema` - Boolean values
- `NumberSchema` - Float/decimal values
- `ArraySchema` - Arrays of items
- `ObjectSchema` - Nested objects
- `EnumSchema` - Enumerated values

## How to Add New Actions

### Step 1: Create Action Folder

Create a new folder in `resources/actions/`:

```bash
mkdir -p resources/actions/my-new-action
```

### Step 2: Create Prompt Files

#### For Text Actions:

Create `system.blade.php`:

```blade
You are an expert at [task description].

# Requirements

- [Requirement 1]
- [Requirement 2]
- [Requirement 3]
```

Create `prompt.blade.php`:

```blade
{{ $text }}

[Any additional context or instructions]
```

#### For Structured Output:

Create `schema.php`:

```php
<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'my_response',
    description: 'Response structure',
    properties: [
        new StringSchema('result', 'The generated result'),
    ],
    requiredFields: ['result']
);
```

#### For Audio Actions:

No prompt files needed. Transcription is handled directly by Prism.

### Step 3: Add Action Configuration

Add to `config/statamic/magic-actions.php`:

```php
'actions' => [
    'text' => [
        'my-new-action' => [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.7,
                'max_tokens' => 500,
            ],
        ],
    ],
],
```

### Step 4: Register with Fieldtype

Add to the appropriate fieldtype in `config/statamic/magic-actions.php`:

```php
'fieldtypes' => [
    'Statamic\Fieldtypes\Text' => [
        'actions' => [
            [
                'title' => 'My New Action',
                'action' => 'my-new-action',
            ],
        ],
    ],
],
```

### Step 5: Test

The action is now available in the Statamic control panel for the configured fieldtype.

### Advanced: Vision Actions

For actions that process images:

**System Prompt** (`extract-assets-tags/system.blade.php`):

```blade
Analyze the provided images and extract relevant tags.

Return only tags that are clearly visible or inferable from the images.
```

**User Prompt** (`extract-assets-tags/prompt.blade.php`):

```blade
Extract tags from the provided image(s).
```

The image is automatically loaded from the asset when `asset_path` is provided in the request.

**Frontend Request:**

```javascript
axios.post('/actions/vision', {
  action: 'extract-assets-tags',
  asset_path: '/assets/images/photo.jpg',
})
```

### Advanced: Multi-Image Vision

Pass multiple images via variables:

**User Prompt**:

```blade
Compare these images and identify differences:
```

**Frontend Request:**

```javascript
axios.post('/actions/vision', {
  action: 'compare-images',
  variables: {
    images: ['https://example.com/image1.jpg', 'https://example.com/image2.jpg'],
  },
})
```

### Advanced: Document Analysis

For PDF or document processing:

**Frontend Request:**

```javascript
axios.post('/actions/vision', {
  action: 'extract-document-data',
  variables: {
    document: '/path/to/document.pdf',
  },
})
```

Prism automatically handles document-to-image conversion and analysis.

## Migration Guide

### For Developers Using This Addon

#### Configuration Changes Required

**Old** (`config/statamic/magic-actions.php`):

```php
'prompts' => [
    'custom-action' => [
        'system' => 'System prompt...',
        'user' => 'User prompt with {variable}',
        'model' => 'gpt-4',
        'provider' => 'openai',
    ],
],
```

**New**:

```php
'actions' => [
    'text' => [
        'custom-action' => [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.7,
                'max_tokens' => 500,
            ],
        ],
    ],
],
```

Then create `resources/actions/custom-action/system.blade.php` and `prompt.blade.php`.

#### Environment Variables

No changes required. Provider configuration still uses:

- `OPENAI_API_KEY`
- `ANTHROPIC_API_KEY`

#### API Changes

No breaking changes to the public API or frontend integration.

### For Contributors

#### Service Layer Changes

**Before:**

```php
// Old: Direct OpenAI service injection
public function __construct(OpenAIService $openai)
{
    $this->openai = $openai;
}

// Old: Manual API calls
$response = $this->openai->completion($messages, $model);
```

**After:**

```php
// New: ActionLoader for prompt management
public function __construct(ActionLoader $actionLoader)
{
    $this->actionLoader = $actionLoader;
}

// New: Prism-based processing in jobs
$promptData = $actionLoader->load($action, $variables);
$response = Prism::text()
    ->using($provider, $model)
    ->withSystemPrompt($promptData['systemPrompt'])
    ->withPrompt($promptData['userPrompt'])
    ->asText();
```

#### Job Changes

**Before:** Three separate job classes

```php
ProcessCompletionJob::dispatch($jobId, $action, ['text' => $text]);
ProcessVisionJob::dispatch($jobId, $action, $assetId);
ProcessTranscriptionJob::dispatch($jobId, $action, $assetId);
```

**After:** Single unified job

```php
ProcessPromptJob::dispatch($jobId, $action, $variables, $assetPath);
```

The job automatically routes to the correct capability type based on action configuration.

#### Testing Changes

**Before:** Mock OpenAI HTTP responses

```php
Http::fake([
    'api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'Result']]],
    ]),
]);
```

**After:** Mock Prism facades

```php
Prism::fake([
    Prism\Prism\Testing\PrismFake::text('Generated text response'),
]);
```

### Removed Functionality

The following classes and methods were removed:

1. **OpenAIService** - Replaced by Prism
2. **PromptParserService** - Replaced by ActionLoader with Blade rendering
3. **ProcessCompletionJob** - Merged into ProcessPromptJob
4. **ProcessVisionJob** - Merged into ProcessPromptJob
5. **ProcessTranscriptionJob** - Merged into ProcessPromptJob

### Breaking Changes

None. The public API remains unchanged:

- Same frontend endpoints
- Same request/response formats
- Same configuration structure (with migration path)

### New Capabilities

The Prism refactor enables several capabilities that were not previously available:

1. **Multi-Provider Support**: Switch between OpenAI, Anthropic, etc. per action
2. **Structured Outputs**: Schema-based response validation
3. **Document Analysis**: PDF and document processing (Anthropic)
4. **Multi-Image Vision**: Compare or analyze multiple images
5. **Improved Error Handling**: Provider-agnostic error messages
6. **Better Testing**: Mock at the Prism level instead of HTTP level

## Additional Resources

- [Prism PHP Documentation](https://github.com/echolabsdev/prism)
- [Prism Schema Reference](https://github.com/echolabsdev/prism/blob/main/docs/schemas.md)
- [Statamic Addon Development](https://statamic.dev/extending/addons)

## Support

For questions or issues related to the refactor:

1. Check existing actions in `resources/actions/` for examples
2. Review Prism PHP documentation for advanced features
3. Open an issue on the GitHub repository
