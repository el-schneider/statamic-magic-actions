<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\MagicActions\AltText;
use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractAssetsTags;
use ElSchneider\StatamicMagicActions\MagicActions\ImageCaption;
use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use ElSchneider\StatamicMagicActions\MagicActions\TranscribeAudio;
use ElSchneider\StatamicMagicActions\Services\ContextResolver;
use Statamic\Assets\Asset;

it('resolves entry_content for entry-based actions', function () {
    $action = new ProposeTitle;

    expect($action->contextRequirements())->toBe([
        'text' => 'entry_content',
    ]);
});

it('resolves asset_metadata for AltText', function () {
    expect((new AltText)->contextRequirements())->toBe(['text' => 'asset_metadata']);
});

it('resolves asset_metadata for ImageCaption', function () {
    expect((new ImageCaption)->contextRequirements())->toBe(['text' => 'asset_metadata']);
});

it('resolves asset_metadata for ExtractAssetsTags', function () {
    expect((new ExtractAssetsTags)->contextRequirements())->toBe(['text' => 'asset_metadata']);
});

it('resolves asset_metadata for TranscribeAudio', function () {
    expect((new TranscribeAudio)->contextRequirements())->toBe(['text' => 'asset_metadata']);
});

it('base action defaults to entry_content', function () {
    $action = new class extends BaseMagicAction
    {
        public function type(): string
        {
            return 'text';
        }

        public function schema(): ?Prism\Prism\Schema\ObjectSchema
        {
            return null;
        }

        public function rules(): array
        {
            return [];
        }
    };

    expect($action->contextRequirements())->toBe(['text' => 'entry_content']);
});

it('resolves asset_metadata to filename and dimensions for assets', function () {
    $resolver = app(ContextResolver::class);

    $asset = Mockery::mock(Asset::class);
    $asset->shouldReceive('basename')->andReturn('hero.jpg');
    $asset->shouldReceive('extension')->andReturn('jpg');
    $asset->shouldReceive('size')->andReturn(245760);
    $asset->shouldReceive('width')->andReturn(1920);
    $asset->shouldReceive('height')->andReturn(1080);
    $asset->shouldReceive('metadata')->andReturn([]);
    $asset->shouldReceive('blueprint')->andReturn(null);

    $action = new AltText;
    $result = $resolver->resolve($action, $asset, 'alt');

    expect($result['text'])->toContain('hero.jpg')
        ->toContain('1920x1080')
        ->toContain('jpg');
});

it('returns empty text for entry_content when target is an asset', function () {
    $resolver = app(ContextResolver::class);

    $asset = Mockery::mock(Asset::class);
    $asset->shouldReceive('blueprint')->andReturn(null);

    $action = new ProposeTitle;
    $result = $resolver->resolve($action, $asset, 'title');

    expect($result['text'])->toBe('');
});
