<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->actingAsSuperAdmin();

    // Configure magic actions for testing
    config([
        'statamic.magic-actions' => [
            'fieldtypes' => [
                Statamic\Fieldtypes\Text::class => [
                    'actions' => [
                        ['title' => 'Generate Title', 'action' => 'generate-title'],
                    ],
                ],
                Statamic\Fieldtypes\Textarea::class => [
                    'actions' => [
                        ['title' => 'Generate Content', 'action' => 'generate-content'],
                    ],
                ],
            ],
        ],
    ]);
});

it('can navigate to collection entries', function () {
    // Create a simple collection first
    $collection = Collection::make('pages')
        ->title('Pages')
        ->pastDateBehavior('public')
        ->futureDateBehavior('private')
        ->save();

    // Visit the collections index and verify we can see our collection
    $this
        ->visit('/cp/collections')
        ->assertPathIs('/cp/collections')
        ->assertSee('Pages')
        ->click('Pages')
        ->assertPathIs('/cp/collections/pages');
})->skip('Requires test data setup');

it('can see magic action buttons on text fields', function () {
    // Create a blueprint with magic actions enabled
    $blueprint = Blueprint::make('articles')
        ->setContents([
            'title' => 'Article',
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
                                        'magic_actions_action' => 'generate-title',
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => 'Content',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_action' => 'generate-content',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->setNamespace('collections.articles')
        ->save();

    // Create articles collection using the blueprint
    $collection = Collection::make('articles')
        ->title('Articles')
        ->pastDateBehavior('public')
        ->futureDateBehavior('private')
        ->entryBlueprint('articles')
        ->save();

    // For now, just verify the collection and blueprint were created
    $this
        ->visit('/cp/collections/articles')
        ->screenshot('collection-with-blueprint')
        ->assertPathIs('/cp/collections/articles');
})->skip('Requires test data setup');

it('can trigger a magic action on a text field', function () {
    // Create everything from scratch for this test
    $blueprint = Blueprint::make('posts')
        ->setContents([
            'title' => 'Post',
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
                                        'magic_actions_action' => 'generate-title',
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => 'Content',
                                        'magic_actions_enabled' => true,
                                        'magic_actions_action' => 'generate-content',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->setNamespace('collections.posts')
        ->save();

    // Create posts collection using the blueprint
    $collection = Collection::make('posts')
        ->title('Posts')
        ->pastDateBehavior('public')
        ->futureDateBehavior('private')
        ->entryBlueprint('posts')
        ->save();

    // Create an entry in the collection with minimal data
    $entry = Entry::make()
        ->collection('posts')
        ->id('test-post')
        ->slug('test-post')
        ->save();

    $this
        ->visit('/cp/collections/posts/entries/test-post/edit')
        ->assertPathIs('/cp/collections/posts/entries/test-post')
        ->screenshot('entry-edit-page-with-blueprint')
        ->assertSee('Title')
        ->assertSee('Content');
})->skip('Requires test data setup');

it('can handle magic action errors gracefully', function () {

    $this
        ->visit('/cp/collections/articles/entries/test-article/edit')
        ->click('[data-magic-action="invalid-action"]')
        ->waitFor('.error-message', 10)
        ->assertSee('Error processing magic action');
})->skip('Requires test data setup');

it('can poll job status during long-running actions', function () {

    $this
        ->visit('/cp/collections/articles/entries/test-article/edit')
        // Trigger a longer action like content generation
        ->click('[data-magic-action="generate-content"]')
        // Should see polling indicators
        ->assertSee('Processing...')
        ->wait(2) // Wait to see polling behavior
        ->assertSee('Processing...') // Should still be processing
        ->waitFor('[data-magic-result]', 60); // Wait for completion
})->skip('Requires test data setup and AI provider configuration');

it('can handle multiple magic actions on the same page', function () {

    $this
        ->visit('/cp/collections/articles/entries/test-article/edit')
        // Test that multiple magic actions can run independently
        ->click('[data-magic-action="generate-title"]')
        ->wait(1)
        ->click('[data-magic-action="generate-excerpt"]')
        // Both should process independently
        ->waitFor('[data-magic-result="title"]', 30)
        ->waitFor('[data-magic-result="excerpt"]', 30);
})->skip('Requires test data setup and AI provider configuration');
