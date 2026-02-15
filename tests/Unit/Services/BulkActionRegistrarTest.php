<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Actions\DynamicBulkAction;
use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\BulkActionRegistrar;
use Prism\Prism\Schema\ObjectSchema;
use Statamic\Actions\Action;

final class FakeBulkEnabledAction extends BaseMagicAction
{
    public const string TITLE = 'Fake Bulk Enabled';

    public function type(): string
    {
        return 'text';
    }

    public function schema(): ?ObjectSchema
    {
        return null;
    }

    public function rules(): array
    {
        return [];
    }

    public function supportsBulk(): bool
    {
        return true;
    }
}

final class FakeAlphaBulkAction extends BaseMagicAction
{
    public const string TITLE = 'Alpha Bulk';

    public function type(): string
    {
        return 'text';
    }

    public function schema(): ?ObjectSchema
    {
        return null;
    }

    public function rules(): array
    {
        return [];
    }

    public function supportsBulk(): bool
    {
        return true;
    }
}

final class FakeNonBulkAction extends BaseMagicAction
{
    public const string TITLE = 'Fake Non Bulk';

    public function type(): string
    {
        return 'text';
    }

    public function schema(): ?ObjectSchema
    {
        return null;
    }

    public function rules(): array
    {
        return [];
    }
}

/**
 * @param  array<int, class-string<BaseMagicAction>>  $actionClasses
 * @return array<class-string<BaseMagicAction>, string>
 */
function seedRegistryWithActions(ActionRegistry $registry, array $actionClasses): array
{
    $handles = [];
    $mapping = [];

    foreach ($actionClasses as $actionClass) {
        $instance = new $actionClass();
        $handle = $instance->getHandle();

        $handles[$actionClass] = $handle;
        $mapping[$handle] = $actionClass;
    }

    $reflection = new ReflectionClass($registry);

    $handlesProperty = $reflection->getProperty('handles');
    $handlesProperty->setAccessible(true);
    $handlesProperty->setValue($registry, $mapping);

    $instancesProperty = $reflection->getProperty('instances');
    $instancesProperty->setAccessible(true);
    $instancesProperty->setValue($registry, []);

    return $handles;
}

it('registers bulk-enabled magic actions as container bindings', function () {
    $registry = new ActionRegistry();
    $handles = seedRegistryWithActions($registry, [
        FakeBulkEnabledAction::class,
        FakeNonBulkAction::class,
    ]);

    $bulkHandle = $handles[FakeBulkEnabledAction::class];
    $nonBulkHandle = $handles[FakeNonBulkAction::class];

    app()->instance('statamic.extensions', [
        Action::class => collect(),
    ]);

    (new BulkActionRegistrar($registry))->registerBulkActions();

    $extensions = app('statamic.extensions');
    $bindings = $extensions[Action::class];

    expect(app()->bound("magic-actions.bulk.{$bulkHandle}"))->toBeTrue()
        ->and(app()->bound("magic-actions.bulk.{$nonBulkHandle}"))->toBeFalse()
        ->and($bindings->get("magic-bulk-{$bulkHandle}"))->toBe("magic-actions.bulk.{$bulkHandle}")
        ->and($bindings->has("magic-bulk-{$nonBulkHandle}"))->toBeFalse();
});

it('dynamic bulk action resolves the configured magic action from its binding handle', function () {
    $registry = new ActionRegistry();
    $handles = seedRegistryWithActions($registry, [
        FakeAlphaBulkAction::class,
        FakeBulkEnabledAction::class,
    ]);

    $alphaHandle = $handles[FakeAlphaBulkAction::class];

    app()->instance(ActionRegistry::class, $registry);
    app()->instance('statamic.extensions', [
        Action::class => collect(),
    ]);

    (new BulkActionRegistrar($registry))->registerBulkActions();

    $adapter = app()->make("magic-actions.bulk.{$alphaHandle}");

    expect($adapter)->toBeInstanceOf(DynamicBulkAction::class);

    $payload = $adapter->toArray();

    expect($payload['handle'])->toBe("magic-bulk-{$alphaHandle}")
        ->and($payload['title'])->toBe((new FakeAlphaBulkAction())->getTitle());
});

it('does not register actions that do not support bulk', function () {
    $registry = new ActionRegistry();
    $handles = seedRegistryWithActions($registry, [
        FakeNonBulkAction::class,
    ]);

    $handle = $handles[FakeNonBulkAction::class];

    app()->instance('statamic.extensions', [
        Action::class => collect(),
    ]);

    (new BulkActionRegistrar($registry))->registerBulkActions();

    $extensions = app('statamic.extensions');
    $bindings = $extensions[Action::class];

    expect(app()->bound("magic-actions.bulk.{$handle}"))->toBeFalse()
        ->and($bindings->isEmpty())->toBeTrue();
});
