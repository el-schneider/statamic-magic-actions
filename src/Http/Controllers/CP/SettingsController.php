<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers\CP;

use ElSchneider\StatamicMagicActions\Settings;
use ElSchneider\StatamicMagicActions\Settings\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Http\Controllers\CP\CpController;

final class SettingsController extends CpController
{
    public function __construct(
        Request $request,
        private readonly Blueprint $blueprintBuilder
    ) {
        parent::__construct($request);
    }

    public function index()
    {
        $blueprint = $this->blueprintBuilder->make();
        $settings = Settings::data();
        $values = Blueprint::settingsToValues($settings);

        return view($this->settingsView(), [
            'title' => __('Magic Actions Settings'),
            'values' => $values,
            'formFields' => $this->renderableFields($blueprint),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $blueprint = $this->blueprintBuilder->make();

        $fields = $blueprint->fields()->addValues($request->all());
        $fields->validate();

        $values = $fields->process()->values()->toArray();
        $settings = Blueprint::valuesToSettings($values);

        Settings::save($settings);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Settings saved']);
        }

        return redirect()
            ->route('magic-actions.settings.index')
            ->with('success', __('Settings saved'));
    }

    private function settingsView(): string
    {
        return $this->usesInertiaControlPanel()
            ? 'magic-actions::cp.settings'
            : 'magic-actions::cp.settings-legacy';
    }

    private function usesInertiaControlPanel(): bool
    {
        return class_exists(\Statamic\Http\Middleware\CP\HandleInertiaRequests::class);
    }

    private function renderableFields(StatamicBlueprint $blueprint): array
    {
        $fields = [];

        foreach (($blueprint->contents()['tabs'] ?? []) as $tab) {
            foreach (($tab['sections'] ?? []) as $section) {
                foreach (($section['fields'] ?? []) as $field) {
                    if (! is_array($field) || ! isset($field['handle']) || ! is_array($field['field'] ?? null)) {
                        continue;
                    }

                    $config = $field['field'];

                    $fields[] = [
                        'handle' => (string) $field['handle'],
                        'type' => (string) ($config['type'] ?? 'text'),
                        'display' => (string) ($config['display'] ?? $field['handle']),
                        'instructions' => is_string($config['instructions'] ?? null) ? $config['instructions'] : '',
                        'rows' => is_numeric($config['rows'] ?? null) ? (int) $config['rows'] : 4,
                        'options' => is_array($config['options'] ?? null) ? $config['options'] : [],
                        'placeholder' => is_string($config['placeholder'] ?? null) ? $config['placeholder'] : null,
                    ];
                }
            }
        }

        return $fields;
    }
}
