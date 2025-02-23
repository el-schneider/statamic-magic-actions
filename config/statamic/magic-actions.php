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
                ],
                [
                    'title' => 'Assign Tags from Taxonomies',
                    'handle' => 'assign-tags-from-taxonomies',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Text' => [
            'actions' => [
                [
                    'title' => 'Propose Title',
                    'handle' => 'propose-title',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Textarea' => [
            'actions' => [
                [
                    'title' => 'Extract Meta Description',
                    'handle' => 'extract-meta-description',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Bard' => [
            'actions' => [
                [
                    'title' => 'Create Teaser',
                    'handle' => 'create-teaser',
                ],
            ],
        ],

        'Statamic\Fieldtypes\Assets' => [
            'actions' => [
                [
                    'title' => 'Extract Tags',
                    'handle' => 'extract-assets-tags',
                ],
                [
                    'title' => 'Alt Text',
                    'handle' => 'alt-text',
                ],
            ],
        ],
    ],
];
