<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use ElSchneider\StatamicMagicActions\Settings\Blueprint as SettingsBlueprint;
use Illuminate\Support\Facades\File;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

final class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => ['resources/js/addon.ts'],
        'publicDirectory' => 'resources/dist',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/magic-actions.php', 'statamic.magic-actions');

        $this->registerServices();
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'magic-actions');

        $this->publishes([
            __DIR__.'/../config/statamic/magic-actions.php' => config_path('statamic/magic-actions.php'),
        ], 'statamic-magic-actions-config');

        $this->publishes([
            __DIR__.'/MagicActions' => app_path('MagicActions'),
        ], 'statamic-magic-actions-classes');
    }

    public function bootAddon(): void
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $this->registerNavigation();
    }

    private function registerServices(): void
    {
        $this->app->singleton(ActionLoader::class);
        $this->app->singleton(FieldConfigService::class);
        $this->app->singleton(MagicFieldsConfigBuilder::class);

        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');

            return $registry;
        });

        $this->app->singleton(SettingsBlueprint::class, function ($app) {
            return new SettingsBlueprint($app->make(ActionRegistry::class));
        });
    }

    private function registerNavigation(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('Magic Actions')
                ->route('magic-actions.settings.index')
                ->icon(File::get(__DIR__.'/../resources/js/icons/magic.svg'));
        });
    }
}
