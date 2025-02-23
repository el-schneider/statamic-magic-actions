<?php

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Statamic\Facades\Blueprint;

class FieldConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $blueprints = Blueprint::all();
        $fieldConfigs = [];

        foreach ($blueprints as $blueprint) {
            $contents = $blueprint->contents();
            if (isset($contents['tabs'])) {
                $fieldConfigs[$blueprint->handle()] = $contents;
            }
        }

        return response()->json($fieldConfigs);
    }
}
