<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\MagicActions\AltText;
use ElSchneider\StatamicMagicActions\MagicActions\CreateTeaser;
use ElSchneider\StatamicMagicActions\MagicActions\ExtractTags;
use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Illuminate\Support\Facades\Config;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Terms;
use Statamic\Fieldtypes\Text;
use Statamic\Fieldtypes\Textarea;

function visibleCatalogActionsForField(array $actions, array $fieldConfig): array
{
    if (! ($fieldConfig['magic_actions_enabled'] ?? false)) {
        return [];
    }

    $configuredActions = $fieldConfig['magic_actions_action'] ?? [];

    if (is_string($configuredActions)) {
        $configuredActions = $configuredActions !== '' ? [$configuredActions] : [];
    }

    if (! is_array($configuredActions)) {
        $configuredActions = [];
    }

    return collect($actions)
        ->filter(fn (array $action): bool => in_array($action['handle'], $configuredActions, true))
        ->values()
        ->all();
}

it('builds a catalog grouped by fieldtype component', function () {
    Config::set('statamic.magic-actions.fieldtypes', [
        Terms::class => ['actions' => [ExtractTags::class]],
        Text::class => ['actions' => [ProposeTitle::class]],
        Textarea::class => ['actions' => [CreateTeaser::class]],
        Bard::class => ['actions' => [CreateTeaser::class]],
    ]);

    $catalog = (new MagicFieldsConfigBuilder(app(ActionLoader::class)))->buildCatalog();

    expect(array_keys($catalog))->toBe(['bard', 'relationship', 'text', 'textarea'])
        ->and($catalog['bard'])->toHaveCount(1)
        ->and($catalog['relationship'])->toHaveCount(1)
        ->and($catalog['text'])->toHaveCount(1)
        ->and($catalog['textarea'])->toHaveCount(1);
});

it('includes action metadata from magic action classes', function () {
    Config::set('statamic.magic-actions.fieldtypes', [
        Text::class => ['actions' => [ProposeTitle::class, AltText::class]],
    ]);

    $catalog = (new MagicFieldsConfigBuilder(app(ActionLoader::class)))->buildCatalog();
    $textActions = collect($catalog['text'])->keyBy('handle');

    $proposeTitle = new ProposeTitle();

    expect($textActions)->toHaveKey($proposeTitle->getHandle());

    $proposeTitleConfig = $textActions->get($proposeTitle->getHandle());

    expect($proposeTitleConfig['title'])->toBe($proposeTitle->getTitle())
        ->and($proposeTitleConfig['handle'])->toBe($proposeTitle->getHandle())
        ->and($proposeTitleConfig['actionType'])->toBe($proposeTitle->type())
        ->and($proposeTitleConfig['icon'])->toBe($proposeTitle->icon())
        ->and($proposeTitleConfig['acceptedMimeTypes'])->toBe($proposeTitle->acceptedMimeTypes());
});

it('supports field-level visibility by matching configured magic action handles', function () {
    Config::set('statamic.magic-actions.fieldtypes', [
        Text::class => ['actions' => [ProposeTitle::class, AltText::class]],
    ]);

    $catalog = (new MagicFieldsConfigBuilder(app(ActionLoader::class)))->buildCatalog();
    $textActions = $catalog['text'];

    $disabledFieldActions = visibleCatalogActionsForField($textActions, [
        'magic_actions_enabled' => false,
        'magic_actions_action' => ['propose-title', 'alt-text'],
    ]);

    $enabledFieldActions = visibleCatalogActionsForField($textActions, [
        'magic_actions_enabled' => true,
        'magic_actions_action' => ['propose-title'],
    ]);

    expect($disabledFieldActions)->toBe([])
        ->and($enabledFieldActions)->toHaveCount(1)
        ->and($enabledFieldActions[0]['handle'])->toBe('propose-title');
});
