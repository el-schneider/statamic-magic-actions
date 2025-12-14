<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Listeners;

use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Statamic;

final class ProvideAssetMagicActionsToScript
{
    public function __construct(private MagicFieldsConfigBuilder $configBuilder) {}

    public function handle(AssetContainerBlueprintFound $event): void
    {
        if (! $this->isOnCPRoute()) {
            return;
        }

        $this->provideMagicActionsToScript($event->blueprint);
    }

    private function provideMagicActionsToScript($blueprint): void
    {
        $magicFields = $this->configBuilder->buildFromBlueprint($blueprint);

        Statamic::provideToScript([
            'magicFields' => $magicFields ?? [],
        ]);
    }

    private function isOnCPRoute(): bool
    {
        $routeName = request()->route()?->getName();

        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'statamic.cp.assets');
    }
}
