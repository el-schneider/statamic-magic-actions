<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers\CP;

use ElSchneider\StatamicMagicActions\Settings;
use ElSchneider\StatamicMagicActions\Settings\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Statamic 6+ uses Inertia-based PublishForm
        if (class_exists(\Statamic\CP\PublishForm::class)) {
            return \Statamic\CP\PublishForm::make($blueprint)
                ->title(__('Magic Actions Settings'))
                ->icon('cog')
                ->values($values)
                ->submittingTo(cp_route('magic-actions.settings.update'), 'POST');
        }

        // Statamic 5: Blade view with <publish-form> Vue component
        $fields = $blueprint->fields()->addValues($values)->preProcess();

        return view('magic-actions::cp.settings', [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $blueprint = $this->blueprintBuilder->make();

        // Statamic 6+ has PublishForm::submit() helper
        if (class_exists(\Statamic\CP\PublishForm::class)) {
            $values = \Statamic\CP\PublishForm::make($blueprint)->submit($request->all());
            $settings = Blueprint::valuesToSettings($values);
            Settings::save($settings);

            return response()->json(['saved' => true]);
        }

        // Statamic 5
        $fields = $blueprint->fields()->addValues($request->all());
        $fields->validate();
        $values = $fields->process()->values()->toArray();
        $settings = Blueprint::valuesToSettings($values);

        Settings::save($settings);

        return response()->json(['message' => 'Settings saved']);
    }
}
