<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

use function get_class;

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

        $this->app->singleton(ActionLoader::class, fn () => new ActionLoader());
        $this->app->singleton(FieldConfigService::class, fn () => new FieldConfigService());
        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Publish magic action classes for user customization
        $this->publishes([
            __DIR__.'/MagicActions' => app_path('MagicActions'),
        ], 'magic-actions');
    }

    public function bootAddon()
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $blueprint = $this->extractBlueprintFromRequest();
        $magicFields = $this->buildMagicFieldsConfig($blueprint);

        $providers = config('statamic.magic-actions.providers');

        Statamic::provideToScript([
            'providers' => $providers,
            'magicFields' => $magicFields ?? [],
        ]);
    }

    /**
     * Extract blueprint from the current request path.
     * Handles both entry and asset blueprints.
     */
    private function extractBlueprintFromRequest(): ?Blueprint
    {
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
                $assetFilename = $matches[1];

                // replace the first / with ::
                $assetFilename = preg_replace('/\//', '::', $assetFilename, 1);

                if ($asset = \Statamic\Facades\Asset::findById($assetFilename)) {
                    $blueprint = $asset->blueprint();
                }
            }
        }

        return $blueprint;
    }

    /**
     * Build magic fields configuration from blueprint.
     */
    private function buildMagicFieldsConfig(?Blueprint $blueprint): ?array
    {
        if (! $blueprint) {
            return null;
        }

        return $blueprint->fields()->all()->filter(function ($field) {
            return $field->config()['magic_actions_enabled'] ?? false;
        })->map(function ($field) {
            $fieldtype = get_class($field->fieldtype());
            $action = $field->config()['magic_actions_action'] ?? null;

            if (! $action) {
                return null;
            }

            // Find the action class by its handle from enabled actions
            $actionClass = null;
            foreach (config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'] ?? [] as $actionData) {
                // Handle both FQCN strings and pre-formatted arrays
                $classPath = null;
                $actionHandle = null;

                if (is_string($actionData) && class_exists($actionData)) {
                    $classPath = $actionData;
                } elseif (is_array($actionData) && isset($actionData['action'])) {
                    $actionHandle = $actionData['action'];
                    $explodedHandle = str_replace('-', ' ', $actionHandle);
                    $className = str_replace(' ', '', ucwords($explodedHandle));
                    $classPath = "ElSchneider\\StatamicMagicActions\\MagicActions\\{$className}";
                }

                if ($classPath && class_exists($classPath)) {
                    $instance = new $classPath();
                    if ($instance->getHandle() === $action) {
                        $actionClass = $instance;
                        break;
                    }
                }
            }

            // Ignore field if action is not enabled in addon config
            if (! $actionClass) {
                return null;
            }

            return [
                'type' => $field->type(),
                'action' => $action,
                'component' => $field->fieldtype()->component(),
                'title' => $actionClass->getTitle(),
                'promptType' => $actionClass->config()['type'],
            ];
        })->filter()->values()->toArray();
    }
}
