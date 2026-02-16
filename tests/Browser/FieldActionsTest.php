<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

uses()->group('browser');

beforeEach(function () {
    $suffix = Str::lower(Str::random(8));

    $this->collectionHandle = "articles_{$suffix}";
    $this->blueprintHandle = 'article';

    Collection::make($this->collectionHandle)
        ->title('Articles')
        ->save();

    $blueprintPath = Blueprint::directory()."/collections/{$this->collectionHandle}";
    if (! is_dir($blueprintPath)) {
        mkdir($blueprintPath, 0755, true);
    }

    Blueprint::make($this->blueprintHandle)
        ->setNamespace("collections.{$this->collectionHandle}")
        ->setContents([
            'title' => 'Article',
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_action' => ['propose-title'],
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'textarea',
                                    ],
                                ],
                                [
                                    'handle' => 'teaser',
                                    'field' => [
                                        'type' => 'textarea',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_source' => 'content',
                                        'magic_actions_action' => ['create-teaser', 'extract-meta-description'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->save();

    $this->entry = Entry::make()
        ->id("entry-{$suffix}")
        ->collection($this->collectionHandle)
        ->blueprint($this->blueprintHandle)
        ->slug("entry-{$suffix}")
        ->set('title', 'Initial title')
        ->set('content', 'This is test content used as source text for field actions.')
        ->set('teaser', '');

    $this->entry->save();
});

it('displays magic action button on fields with magic actions enabled', function () {
    $url = "/cp/collections/{$this->collectionHandle}/entries/{$this->entry->id()}";

    visit($url)
        ->assertScript('Boolean(window.StatamicConfig?.magicActionCatalog && Object.keys(window.StatamicConfig.magicActionCatalog).length > 0)', true)
        ->assertPresent('.publish-field__title .field-dropdown .quick-list-content a')
        ->assertPresent('.publish-field__teaser .field-dropdown .quick-list-content a');
});

it('shows action dropdown with multiple configured actions', function () {
    $url = "/cp/collections/{$this->collectionHandle}/entries/{$this->entry->id()}";

    visit($url)
        ->click('.publish-field__teaser .field-dropdown .rotating-dots-button')
        ->assertSee('Create Teaser')
        ->assertSee('Extract Meta Description');
});
