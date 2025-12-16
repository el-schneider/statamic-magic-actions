<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Listeners;

use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Statamic;

final class ProvideMagicActionsToScript
{
    public function __construct(private MagicFieldsConfigBuilder $configBuilder) {}

    public function handle(EntryBlueprintFound|AssetContainerBlueprintFound $event): void
    {
        if (! $this->isMatchingRoute($event)) {
            return;
        }

        $magicFields = $this->configBuilder->buildFromBlueprint($event->blueprint);

        Statamic::provideToScript([
            'magicFields' => $magicFields ?? [],
        ]);
    }

    private function isMatchingRoute(EntryBlueprintFound|AssetContainerBlueprintFound $event): bool
    {
        $routeName = request()->route()?->getName();

        if (! $routeName) {
            return false;
        }

        $expectedPrefix = match (true) {
            $event instanceof EntryBlueprintFound => 'statamic.cp.collections',
            $event instanceof AssetContainerBlueprintFound => 'statamic.cp.assets',
        };

        return str_starts_with($routeName, $expectedPrefix);
    }
}
