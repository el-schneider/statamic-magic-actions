<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    Config::set('statamic.magic-actions.providers.openai.api_key', 'test-openai-key');
    Config::set('statamic.magic-actions.cli.queue', true);
    Config::set('statamic.magic-actions.cli.overwrite', false);
});

function createEntryForMagicRun(array $data = []): array
{
    $suffix = Str::lower(Str::random(8));
    $collectionHandle = "magic_run_{$suffix}";
    $blueprintHandle = 'page';
    $entryId = "entry-{$suffix}";

    Collection::make($collectionHandle)
        ->title('Magic Run Test Collection')
        ->save();

    Blueprint::make($blueprintHandle)
        ->setNamespace("collections.{$collectionHandle}")
        ->setContents([
            'title' => 'Page',
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
                                        'magic_actions_action' => 'propose-title',
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'textarea',
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
        ->id($entryId)
        ->collection($collectionHandle)
        ->blueprint($blueprintHandle)
        ->set('title', $data['title'] ?? '')
        ->set('content', $data['content'] ?? 'Original content for magic action.');

    $entry->save();

    return [
        'collection' => $collectionHandle,
        'entry_id' => $entry->id(),
    ];
}

it('does not write field values when using dry-run', function () {
    $target = createEntryForMagicRun(['title' => '']);

    $before = Entry::find($target['entry_id']);
    expect($before)->not->toBeNull();
    expect($before?->get('title'))->toBe('');

    $this->artisan('statamic:magic:run', [
        '--entry' => $target['entry_id'],
        '--field' => 'title',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run: no changes will be made.')
        ->assertExitCode(Command::SUCCESS);

    $after = Entry::find($target['entry_id']);
    expect($after)->not->toBeNull();
    expect($after?->get('title'))->toBe('');
});

it('writes results during synchronous execution', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Generated CLI Title',
        ]),
    ]);

    $target = createEntryForMagicRun(['title' => '', 'content' => 'Seed content']);

    $this->artisan('statamic:magic:run', [
        '--entry' => $target['entry_id'],
        '--field' => 'title',
        '--no-queue' => true,
    ])->assertExitCode(Command::SUCCESS);

    $updated = Entry::find($target['entry_id']);

    expect($updated)->not->toBeNull();
    expect($updated?->get('title'))->toBe('Generated CLI Title');
});

it('fails when overwrite and no-overwrite flags are used together', function () {
    $target = createEntryForMagicRun();

    $this->artisan('statamic:magic:run', [
        '--entry' => $target['entry_id'],
        '--field' => 'title',
        '--overwrite' => true,
        '--no-overwrite' => true,
    ])
        ->expectsOutputToContain('Use either --overwrite or --no-overwrite, not both.')
        ->assertExitCode(Command::FAILURE);
});

it('requires at least one target flag', function () {
    $this->artisan('statamic:magic:run', [
        '--field' => 'title',
    ])
        ->expectsOutputToContain('Provide at least one target via --collection=, --entry=, or --asset=')
        ->assertExitCode(Command::FAILURE);
});
