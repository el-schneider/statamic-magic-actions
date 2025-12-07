<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Jobs;

use ElSchneider\StatamicMagicActions\Exceptions\OpenAIApiException;
use ElSchneider\StatamicMagicActions\Services\ActionLoader;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Statamic\Facades\Asset;

final class ProcessPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $jobId;
    private string $action;
    private array $variables;
    private ?string $assetPath = null;

    public function __construct(
        string $jobId,
        string $action,
        array $variables,
        ?string $assetPath = null
    ) {
        $this->jobId = $jobId;
        $this->action = $action;
        $this->variables = $variables;
        $this->assetPath = $assetPath;
    }

    public function handle(ActionLoader $actionLoader): void
    {
        try {
            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'processing',
                'message' => 'Processing request...',
            ], 3600);

            $promptData = $actionLoader->load($this->action, $this->variables);

            // Route to appropriate Prism method based on prompt type
            if ($promptData['type'] === 'text') {
                $response = $this->handleTextPrompt($promptData);
            } elseif ($promptData['type'] === 'audio') {
                $response = $this->handleAudioPrompt($promptData);
            } else {
                throw new Exception("Unknown prompt type: {$promptData['type']}");
            }

            Cache::put("magic_actions_job_{$this->jobId}", [
                'status' => 'completed',
                'data' => $response,
            ], 3600);

        } catch (Exception $e) {
            Log::error('Job error', [
                'job_id' => $this->jobId,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
            $this->handleError($e->getMessage());
        }
    }

    private function handleTextPrompt(array $promptData): array
    {
        $provider = $promptData['provider'];
        $model = $promptData['model'];
        $parameters = $promptData['parameters'];

        // Collect media (images, documents, etc.) from variables
        $media = $this->extractMedia($this->variables);

        // Build Prism request
        $prismRequest = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($promptData['systemPrompt']);

        // Add prompt with media if present
        if (!empty($media)) {
            $prismRequest->withPrompt($promptData['userPrompt'], $media);
        } else {
            $prismRequest->withPrompt($promptData['userPrompt']);
        }

        // Apply parameters
        if (isset($parameters['temperature'])) {
            $prismRequest->usingTemperature($parameters['temperature']);
        }
        if (isset($parameters['max_tokens'])) {
            $prismRequest->withMaxTokens($parameters['max_tokens']);
        }

        // Use structured output if schema exists
        if (isset($promptData['schema'])) {
            $response = Prism::structured()
                ->using($provider, $model)
                ->withSystemPrompt($promptData['systemPrompt']);

            if (!empty($media)) {
                $response->withPrompt($promptData['userPrompt'], $media);
            } else {
                $response->withPrompt($promptData['userPrompt']);
            }

            $response->withSchema($promptData['schema']);

            if (isset($parameters['temperature'])) {
                $response->usingTemperature($parameters['temperature']);
            }
            if (isset($parameters['max_tokens'])) {
                $response->withMaxTokens($parameters['max_tokens']);
            }

            $result = $response->asStructured();
            return $result->structured;
        } else {
            $result = $prismRequest->asText();
            return ['text' => $result->text];
        }
    }

    /**
     * Extract media objects from variables
     * Supports: image, images, document, documents, audio, video
     */
    private function extractMedia(array $variables): array
    {
        $media = [];

        // Handle vision assets - load from Statamic asset path
        if ($this->assetPath && !isset($variables['image']) && !isset($variables['images'])) {
            $asset = Asset::find($this->assetPath);
            if ($asset) {
                $variables['image'] = $asset->url();
            }
        }

        // Handle image data
        if (isset($variables['image'])) {
            $media[] = $this->createImage($variables['image']);
        }
        if (isset($variables['images']) && is_array($variables['images'])) {
            foreach ($variables['images'] as $image) {
                $media[] = $this->createImage($image);
            }
        }

        // Handle document data
        if (isset($variables['document'])) {
            $media[] = $this->createDocument($variables['document']);
        }
        if (isset($variables['documents']) && is_array($variables['documents'])) {
            foreach ($variables['documents'] as $doc) {
                $media[] = $this->createDocument($doc);
            }
        }

        return $media;
    }

    /**
     * Create Image object from various formats
     * Supports: URL, base64, file path
     */
    private function createImage($imageData): Image
    {
        if (is_string($imageData)) {
            // Check if it's a URL
            if (filter_var($imageData, FILTER_VALIDATE_URL)) {
                return Image::fromUrl($imageData);
            }
            // Check if it's base64
            if (strpos($imageData, 'data:image/') === 0) {
                $base64 = preg_replace('/^data:image\/[^;]+;base64,/', '', $imageData);
                return Image::fromBase64($base64);
            }
            // Treat as local path
            if (file_exists($imageData)) {
                return Image::fromLocalPath($imageData);
            }
        }

        throw new Exception("Unable to determine image format for: {$imageData}");
    }

    /**
     * Create Document object from various formats
     * Supports: local path, URL
     */
    private function createDocument($documentData): Document
    {
        if (is_string($documentData)) {
            // Check if it's a URL
            if (filter_var($documentData, FILTER_VALIDATE_URL)) {
                return Document::fromUrl($documentData);
            }
            // Treat as local path
            if (file_exists($documentData)) {
                return Document::fromLocalPath($documentData);
            }
        }

        throw new Exception("Unable to determine document format for: {$documentData}");
    }

    private function handleAudioPrompt(array $promptData): array
    {
        if (!$this->assetPath) {
            throw new Exception('Asset path required for audio prompts');
        }

        $provider = $promptData['provider'];
        $model = $promptData['model'];
        $parameters = $promptData['parameters'];

        // Get asset file path
        $asset = Asset::find($this->assetPath);
        if (!$asset) {
            throw new Exception('Audio asset not found');
        }

        $audioFile = Audio::fromUrl($asset->url());

        $response = Prism::audio()
            ->using($provider, $model)
            ->withInput($audioFile);

        if (!empty($parameters)) {
            $response->withProviderOptions($parameters);
        }

        $result = $response->asText();
        return ['text' => $result->text];
    }

    private function handleError(string $message): void
    {
        Cache::put("magic_actions_job_{$this->jobId}", [
            'status' => 'failed',
            'error' => $message,
        ], 3600);
    }
}
