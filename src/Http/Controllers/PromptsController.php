<?php

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use ElSchneider\StatamicMagicActions\Services\FieldConfigService;
use ElSchneider\StatamicMagicActions\Services\PromptsService;

class PromptsController extends Controller
{
    protected FieldConfigService $fieldConfigService;
    protected PromptsService $promptsService;

    public function __construct(
        FieldConfigService $fieldConfigService,
        PromptsService $promptsService
    ) {
        $this->fieldConfigService = $fieldConfigService;
        $this->promptsService = $promptsService;
    }

    /**
     * Get all available prompts
     */
    public function index(): JsonResponse
    {
        $fieldtypesWithPrompts = $this->fieldConfigService->getFieldtypesWithPrompts();

        $prompts = collect($fieldtypesWithPrompts)
            ->pluck('actions')
            ->flatten(1)
            ->unique('handle')
            ->map(function ($action) {
                $prompt = $this->promptsService->getPromptByHandle($action['handle']);

                return $prompt ? [
                    'handle' => $action['handle'],
                    'prompt' => $prompt
                ] : null;
            })
            ->filter()
            ->values();

        return response()->json($prompts);
    }
}
