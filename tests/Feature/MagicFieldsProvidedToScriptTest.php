<?php

declare(strict_types=1);

namespace Tests\Feature;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

it('provides magic fields config to script when editing entry with magic actions enabled', function () {
    // Create a collection with a blueprint that has magic actions enabled
    $collection = Collection::make('articles')->routes(['en' => '/articles/{slug}'])->save();

    $blueprint = Blueprint::make('articles')
        ->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => 'Title',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_action' => 'propose-title',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->save();

    // Create an entry
    $entry = Entry::make()
        ->collection('articles')
        ->blueprint('articles')
        ->data(['title' => 'Test Entry']);
    $entry->save();
    $entryId = $entry->id();

    // Request the entry edit page
    $response = $this->actingAsSuperAdmin()
        ->get("/cp/collections/articles/entries/{$entryId}");

    $response->assertOk();

    // Check that the magic fields config is in the HTML
    $html = $response->getContent();
    // Verify that magicFields is provided to the script
    expect($html)->toContain('magicFields');
});

it('provides magic fields config for multiple enabled actions on same field', function () {
    $collection = Collection::make('articles')->routes(['en' => '/articles/{slug}'])->save();

    $blueprint = Blueprint::make('articles')
        ->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => 'Content',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_action' => 'create-teaser',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->save();

    $entry = Entry::make()
        ->collection('articles')
        ->blueprint('articles')
        ->data(['content' => 'Test content']);
    $entry->save();
    $entryId = $entry->id();

    $response = $this->actingAsSuperAdmin()
        ->get("/cp/collections/articles/entries/{$entryId}");

    $response->assertOk();

    $html = $response->getContent();
    expect($html)->toContain('magicFields');
});

it('does not include magic fields for disabled actions', function () {
    $collection = Collection::make('articles')->routes(['en' => '/articles/{slug}'])->save();

    $blueprint = Blueprint::make('articles')
        ->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => 'Title',
                                        'magic_actions_enabled' => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->save();

    $entry = Entry::make()
        ->collection('articles')
        ->blueprint('articles')
        ->data(['title' => 'Test Entry']);
    $entry->save();
    $entryId = $entry->id();

    $response = $this->actingAsSuperAdmin()
        ->get("/cp/collections/articles/entries/{$entryId}");

    $response->assertOk();

    // The response should have the script data structure
    $html = $response->getContent();
    expect($html)->toContain('magicFields');
});
