<?php

namespace ElSchneider\StatamicMagicActions;

use ElSchneider\StatamicMagicActions\Http\Controllers\PromptsController;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\PromptsService;
use Illuminate\Support\Facades\Route;
use Statamic\Providers\AddonServiceProvider;
use ElSchneider\StatamicMagicActions\Http\Controllers\FieldConfigController;
use Statamic\Statamic;
use Statamic\Facades\Entry;

class ServiceProvider extends AddonServiceProvider
{
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

        $this->app->singleton(FieldConfigService::class);
        $this->app->singleton(PromptsService::class);
    }

    public function bootAddon()
    {
        $this->app->make(FieldConfigService::class)->registerFieldConfigs();

        $this->registerActionRoutes(function () {
            Route::get('prompts', [PromptsController::class, 'index'])->name('prompts.index');
            Route::get('field-configs', [FieldConfigController::class, 'index'])->name('field-configs.index');
        });

        $magicFields = Entry::find(basename(request()->path()))?->blueprint->fields()->all()->filter(function ($field) {
            return $field->config()['magic_tags_enabled'] ?? false;
        })->map(function ($field) {
            $fieldtype = get_class($field->fieldtype());
            $action = $field->config()['magic_tags_action'];
            $title = collect(config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'])->firstWhere('handle', $action)['title'];

            return [
                'type' => $field->type(),
                'action' => $field->config()['magic_tags_action'],
                'component' => $field->fieldtype()->component(),
                'title' => $title,
                'prompt' => $this->app->make(PromptsService::class)->getPromptByHandle($action),
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
