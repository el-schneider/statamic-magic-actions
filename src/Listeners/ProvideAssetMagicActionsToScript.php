<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Listeners;

use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Statamic;

final class ProvideAssetMagicActionsToScript
{
    public function __construct(private readonly MagicFieldsConfigBuilder $configBuilder) {}

    public function handle(AssetContainerBlueprintFound $event): void
    {
        if (! $this->isOnCPRoute()) {
            return;
        }

        $this->provideMagicActionsToScript($event->blueprint);
    }

    public function provideForAssetRoutes(): void
    {
        if (! $this->isOnCPRoute()) {
            return;
        }

        $route = request()->route();
        $containerParam = $route?->parameter('asset_container');

        if (! $containerParam) {
            return;
        }

        $container = is_string($containerParam) ? AssetContainer::findByHandle($containerParam) : $containerParam;

        if (! $container) {
            return;
        }

        $blueprint = $container->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return;
        }

        $this->provideMagicActionsToScript($blueprint);
    }

    private function provideMagicActionsToScript(Blueprint $blueprint): void
    {
        $magicFields = $this->configBuilder->buildFromBlueprint($blueprint);

        Statamic::provideToScript([
            'magicFields' => $magicFields ?: [],
        ]);
    }

    private function isOnCPRoute(): bool
    {
        $routeName = request()->route()?->getName();

        return is_string($routeName) && str_starts_with($routeName, 'statamic.cp.assets');
    }
}
