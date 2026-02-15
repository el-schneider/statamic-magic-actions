<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Listeners\ProvideAssetMagicActionsToScript;
use ElSchneider\StatamicMagicActions\Listeners\ProvideEntryMagicActionsToScript;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use ElSchneider\StatamicMagicActions\Settings\Blueprint as SettingsBlueprint;
use Illuminate\Support\Facades\File;
use Statamic\Events\AssetBlueprintFound;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/addon.ts',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $listen = [
        EntryBlueprintFound::class => [
            ProvideEntryMagicActionsToScript::class,
        ],
        AssetBlueprintFound::class => [
            ProvideAssetMagicActionsToScript::class,
        ],
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/magic-actions.php', 'statamic.magic-actions');

        $this->app->singleton(ActionLoader::class, fn () => new ActionLoader());
        $this->app->singleton(JobTracker::class, fn () => new JobTracker());
        $this->app->singleton(ActionExecutor::class, function ($app) {
            return new ActionExecutor(
                $app->make(ActionLoader::class),
                $app->make(JobTracker::class)
            );
        });
        $this->app->singleton(FieldConfigService::class, fn () => new FieldConfigService());
        $this->app->singleton(MagicFieldsConfigBuilder::class, fn () => new MagicFieldsConfigBuilder());
        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');

            return $registry;
        });
        $this->app->singleton(SettingsBlueprint::class, function ($app) {
            return new SettingsBlueprint($app->make(ActionRegistry::class));
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'magic-actions');

        // Publish magic action classes for user customization
        $this->publishes([
            __DIR__.'/MagicActions' => app_path('MagicActions'),
        ], 'magic-actions');
    }

    public function bootAddon()
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        Nav::extend(function ($nav) {
            $nav->tools('Magic Actions')
                ->route('magic-actions.settings.index')
                ->icon(File::get(__DIR__.'/../resources/js/icons/magic.svg'));
        });
    }
}
