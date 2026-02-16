<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Commands\MagicRunCommand;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\BulkActionRegistrar;
use ElSchneider\StatamicMagicActions\Services\ContextResolver;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use ElSchneider\StatamicMagicActions\Services\ProviderConfig;
use ElSchneider\StatamicMagicActions\Settings\Blueprint as SettingsBlueprint;
use Illuminate\Support\Facades\File;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

final class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [];

    protected $vite = [
        'input' => [
            'resources/js/addon.ts',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/magic-actions.php', 'statamic.magic-actions');

        $this->app->singleton(ProviderConfig::class);
        $this->app->singleton(ActionLoader::class);
        $this->app->singleton(JobTracker::class);
        $this->app->singleton(ContextResolver::class);
        $this->app->singleton(ActionExecutor::class);
        $this->app->singleton(FieldConfigService::class);
        $this->app->singleton(MagicFieldsConfigBuilder::class);
        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');

            return $registry;
        });
        $this->app->singleton(BulkActionRegistrar::class);
        $this->app->singleton(SettingsBlueprint::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'magic-actions');

        $this->publishes([
            __DIR__.'/MagicActions' => app_path('MagicActions'),
        ], 'statamic-magic-actions-actions');

        $this->publishes([
            __DIR__.'/../config/statamic/magic-actions.php' => config_path('statamic/magic-actions.php'),
        ], 'statamic-magic-actions-config');
    }

    public function bootAddon()
    {
        $this->commands([MagicRunCommand::class]);

        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $this->app->make(BulkActionRegistrar::class)->registerBulkActions();

        $this->provideGlobalMagicActionCatalog();

        Nav::extend(function ($nav) {
            $nav->tools('Magic Actions')
                ->route('magic-actions.settings.index')
                ->icon(File::get(__DIR__.'/../resources/js/icons/magic.svg'));
        });
    }

    private function provideGlobalMagicActionCatalog(): void
    {
        if (! $this->isControlPanelRequest()) {
            return;
        }

        $catalog = $this->app->make(MagicFieldsConfigBuilder::class)->buildCatalog();

        Statamic::provideToScript([
            'magicActionCatalog' => $catalog,
        ]);
    }

    private function isControlPanelRequest(): bool
    {
        if ($this->app->runningInConsole()) {
            return false;
        }

        $cpRoute = mb_trim((string) config('statamic.cp.route', 'cp'), '/');
        if ($cpRoute === '') {
            return false;
        }

        $requestPath = mb_trim(request()->path(), '/');

        return $requestPath === $cpRoute || str_starts_with($requestPath, $cpRoute.'/');
    }
}
