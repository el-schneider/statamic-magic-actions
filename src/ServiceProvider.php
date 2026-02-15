<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Commands\MagicRunCommand;
use ElSchneider\StatamicMagicActions\Listeners\ProvideAssetMagicActionsToScript;
use ElSchneider\StatamicMagicActions\Listeners\ProvideEntryMagicActionsToScript;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\BulkActionRegistrar;
use ElSchneider\StatamicMagicActions\Services\ContextResolver;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use ElSchneider\StatamicMagicActions\Settings\Blueprint as SettingsBlueprint;
use Illuminate\Support\Facades\File;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [];

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
        AssetContainerBlueprintFound::class => [
            ProvideAssetMagicActionsToScript::class,
        ],
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/magic-actions.php', 'statamic.magic-actions');

        $this->app->singleton(ActionLoader::class, fn () => new ActionLoader());
        $this->app->singleton(JobTracker::class, fn () => new JobTracker());
        $this->app->singleton(ContextResolver::class, fn () => new ContextResolver());
        $this->app->singleton(ActionExecutor::class, fn ($app) => new ActionExecutor(
            $app->make(ActionLoader::class),
            $app->make(JobTracker::class),
            $app->make(ContextResolver::class)
        ));
        $this->app->singleton(FieldConfigService::class, fn () => new FieldConfigService());
        $this->app->singleton(
            MagicFieldsConfigBuilder::class,
            fn ($app) => new MagicFieldsConfigBuilder($app->make(ActionLoader::class))
        );
        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');

            return $registry;
        });
        $this->app->singleton(BulkActionRegistrar::class, fn ($app) => new BulkActionRegistrar($app->make(ActionRegistry::class)));
        $this->app->singleton(SettingsBlueprint::class, fn () => new SettingsBlueprint());
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'magic-actions');

        $this->publishes([
            __DIR__.'/MagicActions' => app_path('MagicActions'),
        ], 'magic-actions');

        $this->publishes([
            __DIR__.'/../config/statamic/magic-actions.php' => config_path('statamic/magic-actions.php'),
        ], 'magic-actions-config');
    }

    public function bootAddon()
    {
        $this->commands([MagicRunCommand::class]);

        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $this->app->make(BulkActionRegistrar::class)->registerBulkActions();

        view()->composer('statamic::assets.browse', function () {
            $this->app->make(ProvideAssetMagicActionsToScript::class)->provideForAssetRoutes();
        });

        Nav::extend(function ($nav) {
            $nav->tools('Magic Actions')
                ->route('magic-actions.settings.index')
                ->icon(File::get(__DIR__.'/../resources/js/icons/magic.svg'));
        });
    }
}
