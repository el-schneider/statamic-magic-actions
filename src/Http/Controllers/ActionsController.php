<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Http\Controllers;

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Entry as EntryFacade;

final class ActionsController extends Controller
{
    public function __construct(
        private readonly JobTracker $jobTracker,
        private readonly ActionExecutor $actionExecutor,
        private readonly ActionLoader $actionLoader,
    ) {}

    /**
     * Start a completion job.
     */
    public function completion(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string',
            'action' => 'required|string',
            'context_type' => 'sometimes|string',
            'context_id' => 'sometimes|string',
            'field_handle' => 'sometimes|string',
        ]);

        return $this->executeActionRequest(
            $request,
            'Completion',
            (string) $request->input('action'),
            [
                'variables' => ['text' => (string) $request->input('text')],
                'asset_path' => null,
            ],
        );
    }

    /**
     * Start a vision job.
     */
    public function vision(Request $request): JsonResponse
    {
        $request->validate([
            'asset_path' => 'required|string',
            'action' => 'required|string',
            'variables' => 'sometimes|array',
            'context_type' => 'sometimes|string',
            'context_id' => 'sometimes|string',
            'field_handle' => 'sometimes|string',
        ]);

        return $this->executeActionRequest(
            $request,
            'Vision',
            (string) $request->input('action'),
            [
                'asset_path' => (string) $request->input('asset_path'),
                'variables' => $request->input('variables', []),
            ],
        );
    }

    /**
     * Start a transcription job.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'asset_path' => 'required|string',
            'action' => 'required|string',
            'context_type' => 'sometimes|string',
            'context_id' => 'sometimes|string',
            'field_handle' => 'sometimes|string',
        ]);

        return $this->executeActionRequest(
            $request,
            'Transcription',
            (string) $request->input('action'),
            ['asset_path' => (string) $request->input('asset_path')],
        );
    }

    /**
     * Check the status of a job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $job = $this->jobTracker->getJob($jobId);

        if (! $job) {
            Log::warning('Job not found in cache', ['job_id' => $jobId]);

            return response()->json(['error' => $this->t('api.errors.job_not_found')], 404);
        }

        return response()->json($job);
    }

    /**
     * Check the status of a batch.
     */
    public function batchStatus(string $batchId): JsonResponse
    {
        $batch = $this->jobTracker->getBatch($batchId);

        if (! $batch) {
            Log::warning('Batch not found in cache', ['batch_id' => $batchId]);

            return response()->json(['error' => $this->t('api.errors.batch_not_found')], 404);
        }

        return response()->json($batch);
    }

    private function apiKeyNotConfiguredError(string $action, string $errorMessage): JsonResponse
    {
        Log::warning("$action request rejected: {$errorMessage}");

        return response()->json([
            'error' => $errorMessage,
            'message' => $this->t('api.messages.configure_api_key'),
        ], 500);
    }

    /**
     * @return array{type: string, id: string, field: string}|null
     */
    private function extractContext(Request $request): ?array
    {
        $contextType = $this->nonEmptyString($request->input('context_type'));
        $contextId = $this->nonEmptyString($request->input('context_id'));
        $fieldHandle = $this->nonEmptyString($request->input('field_handle'));

        if ($contextType === null || $contextId === null || $fieldHandle === null) {
            return null;
        }

        return [
            'type' => $contextType,
            'id' => $contextId,
            'field' => $fieldHandle,
        ];
    }

    private function executeActionRequest(
        Request $request,
        string $requestName,
        string $action,
        array $options = [],
    ): JsonResponse {
        try {
            if (! $this->actionLoader->exists($action)) {
                return response()->json(['error' => $this->t('api.errors.action_not_found')], 404);
            }

            $context = $this->extractContext($request);

            if (! $context) {
                return response()->json(['error' => $this->t('api.errors.context_required')], 400);
            }

            $target = $this->resolveTarget($context, $options);

            if (! $target) {
                Log::warning("{$requestName} request target not found", [
                    'action' => $action,
                    'context' => $context,
                ]);

                return response()->json([
                    'error' => $this->t('api.errors.context_target_not_found', [
                        'type' => $context['type'],
                        'id' => $context['id'],
                    ]),
                    'context_type' => $context['type'],
                    'context_id' => $context['id'],
                ], 404);
            }

            $jobId = $this->actionExecutor->execute($action, $target, $context['field'], $options);

            Log::info('Job dispatched', [
                'job_id' => $jobId,
                'action' => $action,
                'context' => $context,
            ]);

            return response()->json([
                'job_id' => $jobId,
                'status' => 'queued',
                'context' => $context,
            ]);
        } catch (MissingApiKeyException $e) {
            return $this->apiKeyNotConfiguredError($requestName, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::warning("{$requestName} request validation failed", [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @param  array{type: string, id: string, field: string}  $context
     */
    private function resolveTarget(array $context, array $options): Entry|Asset|null
    {
        return match ($context['type']) {
            'entry' => EntryFacade::find($context['id']),
            'asset' => $this->resolveAssetTarget($context['id'])
                ?? $this->resolveAssetByPath($options['asset_path'] ?? null),
            default => null,
        };
    }

    private function resolveAssetTarget(string $contextId): ?Asset
    {
        $asset = AssetFacade::find($contextId);

        if ($asset) {
            return $asset;
        }

        if (! str_contains($contextId, '/') || str_contains($contextId, '::')) {
            return null;
        }

        [$container, $path] = explode('/', $contextId, 2);

        if ($path === '') {
            return null;
        }

        return AssetFacade::find("{$container}::{$path}");
    }

    private function resolveAssetByPath(mixed $path): ?Asset
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return AssetFacade::find($path);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    private function t(string $key, array $replace = []): string
    {
        return __('magic-actions::magic-actions.'.$key, $replace);
    }
}
