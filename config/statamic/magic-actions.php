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
