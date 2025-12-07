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
