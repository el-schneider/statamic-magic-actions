![Magic Actions](images/ma_banner.png)

AI-powered field actions for Statamic v5. Generate alt text, extract tags, create teasers, transcribe audio, and more — directly from the control panel.

## Features

- **Zero-friction workflow**: One-click AI actions integrated directly into field UI
- **Multiple AI providers**: OpenAI and Anthropic via [Prism PHP](https://prismphp.dev/)
- **Background processing**: Generation Jobs run asynchronously with status tracking
- **9 built-in actions**: Alt text, captions, titles, meta descriptions, teasers, tags, transcription
- **Extensible**: Create custom actions with full control over prompts and models

## Installation

```bash
composer require el-schneider/statamic-magic-actions
```

## Configuration

Add your API key to `.env`:

```env
OPENAI_API_KEY=sk-...
# or
ANTHROPIC_API_KEY=sk-ant-...
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=statamic.magic-actions.config
```

## Usage

### 1. Configure a field in your blueprint

In the Statamic control panel, edit any supported fieldtype and enable Magic Actions:

- **Enabled**: Toggle on
- **Action**: Choose from available actions
- **Source**: Field containing source content
- **Mode**: Append or Replace

### 2. Click the magic button

A wand icon appears on configured fields. Click it to run the action.

### 3. Queue processing

For best performance, configure a queue worker:

```bash
php artisan queue:work
```

> Without a queue worker, jobs run synchronously which may cause timeouts for longer operations.

## Built-in Actions

### Text Fields

| Action            | Description                                        |
| ----------------- | -------------------------------------------------- |
| **Propose Title** | Generate SEO-friendly titles from content          |
| **Alt Text**      | Create accessible image descriptions (uses vision) |
| **Image Caption** | Generate narrative captions for images             |

### Textarea Fields

| Action                       | Description                                |
| ---------------------------- | ------------------------------------------ |
| **Extract Meta Description** | SEO-optimized descriptions (max 160 chars) |
| **Create Teaser**            | Engaging preview text (~300 chars)         |
| **Transcribe Audio**         | Convert audio files to text using Whisper  |
| **Image Caption**            | Generate narrative captions for images     |

### Bard Fields

| Action               | Description                            |
| -------------------- | -------------------------------------- |
| **Create Teaser**    | Teaser text formatted for Bard         |
| **Transcribe Audio** | Audio transcription formatted for Bard |
| **Image Caption**    | Image captions formatted for Bard      |

### Terms Fields

| Action                          | Description                              |
| ------------------------------- | ---------------------------------------- |
| **Extract Tags**                | Auto-generate tags from content          |
| **Assign Tags from Taxonomies** | Match content to existing taxonomy terms |
| **Extract Asset Tags**          | Generate tags from image analysis        |

## Configuration Reference

```php
// config/statamic/magic-actions.php
return [
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
    ],

    'fieldtypes' => [
        'Statamic\Fieldtypes\Text' => [
            'actions' => [
                ProposeTitle::class,
                AltText::class,
                ImageCaption::class,
            ],
        ],
        // ... more fieldtype mappings
    ],
];
```

## Custom Actions

Create your own magic actions by extending `BaseMagicAction`:

```php
<?php

namespace App\MagicActions;

use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class Summarize extends BaseMagicAction
{
    public const string TITLE = 'Summarize';

    public function config(): array
    {
        return [
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.5,
                'max_tokens' => 500,
            ],
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'summary_response',
            description: 'Content summary',
            properties: [
                new StringSchema('summary', 'Brief summary'),
            ],
            requiredFields: ['summary']
        );
    }

    public function rules(): array
    {
        return [
            'text' => 'required|string',
        ];
    }

    public function system(): string
    {
        return 'You are a summarization expert. Create concise summaries.';
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}
BLADE;
    }
}
```

Register your action in the config:

```php
'fieldtypes' => [
    'Statamic\Fieldtypes\Textarea' => [
        'actions' => [
            \App\MagicActions\Summarize::class,
        ],
    ],
],
```

## Action Types

| Type     | Use Case                | Model Example                   |
| -------- | ----------------------- | ------------------------------- |
| `text`   | Text-to-text processing | `gpt-4.1`, `claude-sonnet-4-5`  |
| `vision` | Image analysis          | `gpt-4.1`, `claude-sonnet-4-5`  |
| `audio`  | Transcription           | `whisper-1`                     |

## Prompts with Blade

Prompts support Blade syntax with variables:

```php
public function prompt(): string
{
    return <<<'BLADE'
Content: {{ $text }}

Available Tags: {{ $available_tags }}
BLADE;
}
```

## Language Support

Most text actions auto-detect and match the input language. The system prompts instruct the AI to respond in the same language as the source content.

## Requirements

- PHP 8.2+
- Statamic 5.0+
- OpenAI or Anthropic API key
- Queue worker recommended

## License

MIT
