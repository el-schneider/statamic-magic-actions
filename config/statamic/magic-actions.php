<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Providers that should be enabled for magic tags.
    |
    */

    'providers' => [
        'openai' => [
            'name' => 'OpenAI',
            'api_key' => env('OPENAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fieldtypes
    |--------------------------------------------------------------------------
    |
    | Fieldtypes that should be enabled for magic tags.
    |
    */

    'fieldtypes' => [
        'Statamic\Fieldtypes\Terms' => [
            'actions' => [
                [
                    'title' => 'Extract Tags',
                    'handle' => 'extract-tags',
                    'type' => 'completion',
                ],
                [
                    'title' => 'Assign Tags from Taxonomies',
                    'handle' => 'assign-tags-from-taxonomies',
                    'type' => 'completion',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Text' => [
            'actions' => [
                [
                    'title' => 'Propose Title',
                    'handle' => 'propose-title',
                    'type' => 'completion',
                ],
                [
                    'title' => 'Alt Text',
                    'handle' => 'alt-text',
                    'type' => 'vision',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Textarea' => [
            'actions' => [
                [
                    'title' => 'Extract Meta Description',
                    'handle' => 'extract-meta-description',
                    'type' => 'completion',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Bard' => [
            'actions' => [
                [
                    'title' => 'Create Teaser',
                    'handle' => 'create-teaser',
                    'type' => 'completion',
                ],
                [
                    'title' => 'Transcribe Audio',
                    'handle' => 'transcribe-audio',
                    'type' => 'transcription',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Assets' => [
            'actions' => [
                [
                    'title' => 'Extract Tags',
                    'handle' => 'extract-assets-tags',
                    'type' => 'vision',
                ],
            ],
        ],
    ],
];
