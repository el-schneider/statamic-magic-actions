<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Listeners;

use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Facades\AssetContainer;
use Statamic\Statamic;

final class ProvideAssetMagicActionsToScript
{
    public function __construct(private MagicFieldsConfigBuilder $configBuilder) {}

    /**
     * Handle the AssetContainerBlueprintFound event (fires on API requests).
     */
    public function handle(AssetContainerBlueprintFound $event): void
    {
        if (! $this->isOnCPRoute()) {
            return;
        }

        $this->provideMagicActionsToScript($event->blueprint);
    }

    /**
     * Provide magic fields for asset browse/edit pages.
     *
     * The asset edit page is SPA — the blueprint event fires during API calls,
     * not during the initial page render. This method is called from a view
     * composer to ensure magicFields are available on page load.
     */
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

        $container = is_string($containerParam)
            ? AssetContainer::findByHandle($containerParam)
            : $containerParam;

        if (! $container) {
            return;
        }

        $blueprint = $container->blueprint();

        if ($blueprint) {
            $this->provideMagicActionsToScript($blueprint);
        }
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
