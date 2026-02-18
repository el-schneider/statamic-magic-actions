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

        $fields = $blueprint->fields()->addValues($request->all());
        $fields->validate();

        $values = $fields->process()->values()->toArray();
        $settings = Blueprint::valuesToSettings($values);

        Settings::save($settings);

        return response()->json(['message' => __('magic-actions::messages.settings_saved')]);
    }
}
