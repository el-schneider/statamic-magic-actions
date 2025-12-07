<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\PromptParserService;
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
        $this->app->singleton(FieldConfigService::class);
        $this->app->singleton(PromptsService::class);
        $this->app->singleton(PromptParserService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Register views from resources/actions with namespace 'magic-actions'
        $this->loadViewsFrom(
            resource_path('actions'),
            'magic-actions'
        );
    }

    public function bootAddon()
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $blueprint = null;
        $requestPath = basename(request()->path());

        // Try to get blueprint from Entry
        if ($entry = Entry::find($requestPath)) {
            $blueprint = $entry->blueprint;
        }

        // Try to get blueprint from Asset
        if (! $blueprint && str_contains(request()->path(), 'assets')) {
            $assetPath = request()->path();
            $assetFilename = null;

            if (preg_match('/cp\/assets\/browse\/(.+?)\/edit/', $assetPath, $matches)) {
                $assetFilename = '/'.$matches[1];

                if ($asset = \Statamic\Facades\Asset::find($assetFilename)) {
                    $blueprint = $asset->blueprint();
                }
            }

        }

        $magicFields = $blueprint?->fields()->all()->filter(function ($field) {
            return $field->config()['magic_actions_enabled'] ?? false;
        })->map(function ($field) {
            $fieldtype = get_class($field->fieldtype());
            $action = $field->config()['magic_actions_action'];
            $title = collect(config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'])->firstWhere('handle', $action)['title'];
            $actionType = collect(config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'])->firstWhere('handle', $action)['type'] ?? 'completion';

            // We no longer need to pass full prompt content to the frontend
            // Just pass the action handle which will be used to identify the prompt on the backend
            return [
                'type' => $field->type(),
                'action' => $action,
                'actionType' => $actionType,
                'component' => $field->fieldtype()->component(),
                'title' => $title,
            ];
        });

        $providers = config('statamic.magic-actions.providers');

        // dd($providers, $magicFields?->values());

        Statamic::provideToScript([
            'providers' => $providers,
            'magicFields' => $magicFields?->values(),
        ]);
    }
}
