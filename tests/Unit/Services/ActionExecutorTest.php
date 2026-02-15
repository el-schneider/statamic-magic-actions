<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Jobs\ProcessPromptJob;
use ElSchneider\StatamicMagicActions\MagicActions\AltText;
use ElSchneider\StatamicMagicActions\MagicActions\AssignTagsFromTaxonomies;
use ElSchneider\StatamicMagicActions\MagicActions\CreateTeaser;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractAssetsTags;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractMetaDescription;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractTags;
use ElSchneider\StatamicMagicActions\MagicActions\ImageCaption;
use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use ElSchneider\StatamicMagicActions\MagicActions\TranscribeAudio;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Entries\Entry as EntryContract;

final class DummyFieldtype {}

function makeField(object $fieldtype, string $type = 'text', array $config = []): object
{
    return new class($fieldtype, $type, $config)
    {
        public function __construct(
            private readonly object $fieldtype,
            private readonly string $type,
            private readonly array $config,
        ) {}

        public function fieldtype(): object
        {
            return $this->fieldtype;
        }

        public function type(): string
        {
            return $this->type;
        }

        public function config(): array
        {
            return $this->config;
        }
    };
}

function makeEntryTarget(string $fieldHandle, object $field): EntryContract
{
    $blueprint = Mockery::mock();
    $blueprint->shouldReceive('field')->with($fieldHandle)->andReturn($field);

    $entry = Mockery::mock(EntryContract::class);
    $entry->shouldReceive('blueprint')->andReturn($blueprint);
    $entry->shouldReceive('id')->andReturn('entry-1');
    $entry->shouldReceive('get')->withAnyArgs()->andReturn('Entry body content');

    return $entry;
}

function makeAssetTarget(string $fieldHandle, object $field, string $mimeType): AssetContract
{
    $blueprint = Mockery::mock();
    $blueprint->shouldReceive('field')->with($fieldHandle)->andReturn($field);

    $asset = Mockery::mock(AssetContract::class);
    $asset->shouldReceive('blueprint')->andReturn($blueprint);
    $asset->shouldReceive('id')->andReturn('assets::image.file');
    $asset->shouldReceive('mimeType')->andReturn($mimeType);

    return $asset;
}

function readPrivateProperty(object $object, string $property): mixed
{
    $reflection = new ReflectionClass($object);
    $reflectionProperty = $reflection->getProperty($property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($object);
}

beforeEach(function () {
    Cache::flush();

    Config::set('statamic.magic-actions', [
        'providers' => [
            'openai' => ['api_key' => 'test-openai-key'],
            'anthropic' => ['api_key' => 'test-anthropic-key'],
        ],
        'types' => [
            'text' => ['default' => 'openai/gpt-4.1'],
            'vision' => ['default' => 'openai/gpt-4.1'],
            'audio' => ['default' => 'openai/whisper-1'],
        ],
        'fieldtypes' => [],
    ]);
});

it('returns all configured and registered magic actions in getAvailableActions', function () {
    $fieldHandle = 'magic_field';
    $fieldtype = new DummyFieldtype();

    $configuredActions = [
        AltText::class,
        AssignTagsFromTaxonomies::class,
        CreateTeaser::class,
        ExtractAssetsTags::class,
        ExtractMetaDescription::class,
        ExtractTags::class,
        ImageCaption::class,
        ProposeTitle::class,
        TranscribeAudio::class,
    ];

    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', $configuredActions);

    $entry = makeEntryTarget($fieldHandle, makeField($fieldtype));

    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    $available = $executor->getAvailableActions($entry, $fieldHandle);

    $expectedHandles = array_map(
        static fn (string $actionClass): string => (new $actionClass())->getHandle(),
        $configuredActions
    );

    expect($available)->toEqual($expectedHandles);
});

it('returns true from canExecute for a valid action handle', function () {
    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [ProposeTitle::class]);

    $entry = makeEntryTarget($fieldHandle, makeField(new DummyFieldtype()));
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    expect($executor->canExecute('propose-title', $entry, $fieldHandle))->toBeTrue();
});

it('returns false from canExecute for a non-existent action handle', function () {
    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [ProposeTitle::class]);

    $entry = makeEntryTarget($fieldHandle, makeField(new DummyFieldtype()));
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    expect($executor->canExecute('does-not-exist', $entry, $fieldHandle))->toBeFalse();
});

it('rejects unsupported mime types for actions with acceptedMimeTypes', function () {
    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [AltText::class]);

    $asset = makeAssetTarget($fieldHandle, makeField(new DummyFieldtype()), 'text/plain');
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    expect($executor->canExecute('alt-text', $asset, $fieldHandle))->toBeFalse();
});

it('accepts supported mime types for actions with acceptedMimeTypes', function () {
    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [AltText::class]);

    $asset = makeAssetTarget($fieldHandle, makeField(new DummyFieldtype()), 'image/png');
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    expect($executor->canExecute('alt-text', $asset, $fieldHandle))->toBeTrue();
});

it('injects context variables for RequiresContext actions', function () {
    Bus::fake();

    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [AssignTagsFromTaxonomies::class]);

    $entry = makeEntryTarget($fieldHandle, makeField(new DummyFieldtype(), config: ['taxonomy' => 'tags']));
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    $executor->execute('assign-tags-from-taxonomies', $entry, $fieldHandle);

    Bus::assertDispatched(ProcessPromptJob::class, function (ProcessPromptJob $job): bool {
        $variables = readPrivateProperty($job, 'variables');

        expect($variables)->toHaveKeys(['available_tags', 'content'])
            ->and($variables['content'])->toBe('Entry body content');

        return true;
    });
});

it('dispatches ProcessPromptJob when execute is called with a valid action', function () {
    Bus::fake();

    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [ProposeTitle::class]);

    $entry = makeEntryTarget($fieldHandle, makeField(new DummyFieldtype()));
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    $jobId = $executor->execute('propose-title', $entry, $fieldHandle, [
        'variables' => ['text' => 'Some content'],
    ]);

    expect($jobId)->toBeString()->not->toBeEmpty();

    Bus::assertDispatched(ProcessPromptJob::class);
});

it('executes synchronously and returns the ai result', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'title' => 'Generated Title',
        ]),
    ]);

    $fieldHandle = 'magic_field';
    Config::set("statamic.magic-actions.fieldtypes.".DummyFieldtype::class.'.actions', [ProposeTitle::class]);

    $entry = makeEntryTarget($fieldHandle, makeField(new DummyFieldtype()));
    $executor = new ActionExecutor(app(ActionLoader::class), app(JobTracker::class));

    $result = $executor->executeSync('propose-title', $entry, $fieldHandle, [
        'variables' => ['text' => 'Source content'],
    ]);

    expect($result)->toBe('Generated Title');
});
