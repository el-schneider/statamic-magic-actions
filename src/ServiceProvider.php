<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\PromptsService;
use Statamic\Facades\Entry;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

final class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/addon.ts',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/magic-actions.php', 'statamic.magic-actions');

        $this->app->singleton(ActionLoader::class, fn() => new ActionLoader());
        $this->app->singleton(FieldConfigService::class, function ($app) {
            return new FieldConfigService($app->make(ActionLoader::class));
        });
        $this->app->singleton(PromptsService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Register views from addon's resources/actions with namespace 'magic-actions'
        $this->loadViewsFrom(
            __DIR__.'/../resources/actions',
            'magic-actions'
        );
    }

    public function bootAddon()
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $blueprint = null;
        $requestPath = request()->path();

        // Try to get blueprint from Entry
        if (str_contains($requestPath, '/collections/')) {
            if (preg_match('/entries\/([^\/]+)$/', $requestPath, $matches)) {
                $entryId = $matches[1];

                if ($entry = Entry::find($entryId)) {
                    $blueprint = $entry->blueprint();
                }
            }
        }

        // Try to get blueprint from Asset
        if (! $blueprint && str_contains($requestPath, 'assets')) {
            if (preg_match('/cp\/assets\/browse\/(.+?)\/edit/', $requestPath, $matches)) {
                $assetFilename = '/'.$matches[1];

                if ($asset = \Statamic\Facades\Asset::find($assetFilename)) {
                    $blueprint = $asset->blueprint();
                }
            }
        }

        $magicFields = null;
        if ($blueprint) {
            $magicFields = $blueprint->fields()->all()->filter(function ($field) {
                return $field->config()['magic_actions_enabled'] ?? false;
            })->map(function ($field) {
                $fieldtype = \get_class($field->fieldtype());
                $action = $field->config()['magic_actions_action'];
                $actionConfig = collect(config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'] ?? [])->firstWhere('action', $action);
                $title = $actionConfig['title'] ?? $action;

                // We no longer need to pass full prompt content to the frontend
                // Just pass the action name which will be used to identify the prompt on the backend
                return [
                    'type' => $field->type(),
                    'action' => $action,
                    'component' => $field->fieldtype()->component(),
                    'title' => $title,
                ];
            });
        }

        $providers = config('statamic.magic-actions.providers');

        Statamic::provideToScript([
            'providers' => $providers,
            'magicFields' => $magicFields ? $magicFields->values()->toArray() : [],
        ]);
    }
}
