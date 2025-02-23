<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

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
