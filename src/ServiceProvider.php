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

        $blueprint = Entry::find(basename(request()->path()))?->blueprint?->contents();

        // dd($blueprint);

        $magicFields = [];

        if ($blueprint) {
            foreach ($blueprint['tabs'] as $tab) {
                foreach ($tab['sections'] as $section) {
                    foreach ($section['fields'] as $fieldConfig) {
                    if (!empty($fieldConfig['field']['magic_tags_enabled'])) {
                        $magicFields[] = [
                            'type' => $fieldConfig['field']['type'],
                            'action' => $fieldConfig['field']['magic_tags_action'],
                            'title' => collect(config('statamic.magic-actions.fieldtypes')['Statamic\Fieldtypes\\' . ucfirst($fieldConfig['field']['type'])]['actions'])
                                ->firstWhere('handle', $fieldConfig['field']['magic_tags_action'])['title'],
                            'prompt' => $this->app->make(PromptsService::class)->getPromptByHandle($fieldConfig['field']['magic_tags_action']),
                        ];
                    }
                }
                }
            }
        }

        $providers = config('statamic.magic-actions.providers');

        Statamic::provideToScript([
            'providers' => $providers,
            'magicFields' => $magicFields,
        ]);
    }
}
