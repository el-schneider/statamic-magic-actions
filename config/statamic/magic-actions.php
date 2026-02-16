<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\MagicActions\AltText;
use ElSchneider\StatamicMagicActions\MagicActions\AssignTagsFromTaxonomies;
use ElSchneider\StatamicMagicActions\MagicActions\CreateTeaser;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractAssetsTags;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractMetaDescription;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractTags;
use ElSchneider\StatamicMagicActions\MagicActions\ImageCaption;
use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use ElSchneider\StatamicMagicActions\MagicActions\TranscribeAudio;

return [
    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Provider credentials for Prism. API keys loaded from environment.
    | Required env vars:
    | - OPENAI_API_KEY for OpenAI models
    | - ANTHROPIC_API_KEY for Anthropic models
    | - GEMINI_API_KEY for Gemini models
    | - MISTRAL_API_KEY for Mistral models
    |
    */
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    |
    | Available models for each action type. Model keys use provider/model
    | format for parsing. Each type has a list of available models and
    | a default to use when no user preference is set.
    |
    */
    'types' => [
        'text' => [
            'models' => [
                'openai/gpt-4.1',
                'openai/gpt-4.1-mini',
                'anthropic/claude-sonnet-4-5',
                'anthropic/claude-haiku-3-5',
                'gemini/gemini-2.0-flash',
                'gemini/gemini-2.5-pro',
                'mistral/mistral-large-latest',
            ],
            'default' => 'openai/gpt-4.1',
        ],
        'vision' => [
            'models' => [
                'openai/gpt-4.1',
                'anthropic/claude-sonnet-4-5',
                'gemini/gemini-2.0-flash',
                'anthropic/claude-haiku-3-5',
            ],
            'default' => 'openai/gpt-4.1',
        ],
        'audio' => [
            'models' => [
                'openai/whisper-1',
                'mistral/voxtral-mini-latest',
            ],
            'default' => 'openai/whisper-1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Path
    |--------------------------------------------------------------------------
    |
    | Path to the YAML file storing user settings. Defaults to content directory
    | for git-trackability.
    |
    */
    'settings_path' => base_path('content/magic-actions/settings.yaml'),

    /*
    |--------------------------------------------------------------------------
    | Auto Run
    |--------------------------------------------------------------------------
    |
    | Configure automatic action execution on entry/asset save events.
    | Disabled by default. When enabled, configured actions run automatically
    | when content is saved (only for empty fields unless overwrite is true).
    |
    */
    'auto_run' => [
        'enabled' => env('MAGIC_ACTIONS_AUTO_RUN', false),
        'overwrite' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | CLI Defaults
    |--------------------------------------------------------------------------
    |
    | Default options for the statamic:magic:run / please magic:run CLI command.
    |
    */
    'cli' => [
        'queue' => true, // Default to queued execution for CLI.
        'overwrite' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for batch job processing.
    |
    */
    'batch' => [
        'cache_ttl' => 86400, // 24 hours in seconds.
        'max_concurrent' => 10, // Max concurrent jobs per batch (placeholder).
    ],

    /*
    |--------------------------------------------------------------------------
    | Fieldtypes
    |--------------------------------------------------------------------------
    |
    | Fieldtypes and their available magic actions.
    | Each action is referenced by its fully qualified class name.
    |
    */
    'fieldtypes' => [
        'Statamic\Fieldtypes\Terms' => [
            'actions' => [
                ExtractTags::class,
                AssignTagsFromTaxonomies::class,
                ExtractAssetsTags::class,
            ],
        ],

        'Statamic\Fieldtypes\Text' => [
            'actions' => [
                ProposeTitle::class,
                AltText::class,
                ImageCaption::class,
            ],
        ],

        'Statamic\Fieldtypes\Textarea' => [
            'actions' => [
                CreateTeaser::class,
                ExtractMetaDescription::class,
                TranscribeAudio::class,
                ImageCaption::class,
            ],
        ],

        'Statamic\Fieldtypes\Bard' => [
            'actions' => [
                CreateTeaser::class,
                TranscribeAudio::class,
                ImageCaption::class,
            ],
        ],
    ],
];
