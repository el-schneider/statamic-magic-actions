<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Exceptions\MissingApiKeyException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OpenAIService
{
    private string $apiKey;

    public function __construct()
    {
        $apiKey = Config::get('statamic.magic-actions.providers.openai.api_key');

        if (! is_string($apiKey) || empty($apiKey)) {
            throw new MissingApiKeyException();
        }

        $this->apiKey = $apiKey;
    }

    /**
     * Make a text completion request to OpenAI API
     *
     * @param  array  $messages  The messages to send to the API
     * @param  string  $model  The model to use (default: gpt-4)
     * @return array|null The response from the API
     */
    public function completion(array $messages, string $model = 'gpt-4'): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');

            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                Log::error('OpenAI API error: '.$response->body());

                return null;
            }

            return $this->parseResponse($response->json());
        } catch (Throwable $e) {
            Log::error('OpenAI API error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Make a vision request to OpenAI API
     *
     * @param  array  $messages  The messages to send to the API
     * @param  string  $imageUrl  The URL of the image or base64 data
     * @param  string  $model  The model to use (default: gpt-4-vision-preview)
     * @return array|null The response from the API
     */
    public function vision(array $messages, string $imageUrl, string $model = 'gpt-4-vision-preview'): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');

            return null;
        }

        try {
            // Log the incoming messages for debugging
            Log::debug('Vision API input messages:', $messages);

            // Check for empty messages
            if (empty($messages)) {
                Log::error('Empty messages array passed to vision API');

                return null;
            }

            // Get user message and add image
            $userMessage = null;
            foreach ($messages as $message) {
                if ($message['role'] === 'user') {
                    $userMessage = $message;
                    break;
                }
            }

            // If no user message found, use the first message regardless of role
            if (! $userMessage) {
                $userMessage = $messages[0];
                Log::warning('No user message found in vision request, using first message');
            }

            // Prepare message content with image
            $content = [
                [
                    'type' => 'text',
                    'text' => $userMessage['content'] ?? 'Analyze this image',
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $imageUrl,
                    ],
                ],
            ];

            // Prepare final messages array
            $apiMessages = [];

            // Add system message if it exists
            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $apiMessages[] = $message;
                }
            }

            // Add user message with image
            $apiMessages[] = [
                'role' => 'user',
                'content' => $content,
            ];

            Log::debug('Final vision API messages:', $apiMessages);

            $response = Http::withToken($this->apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $apiMessages,
                    'max_tokens' => 1000,
                ]);

            if ($response->failed()) {
                Log::error('OpenAI Vision API error: '.$response->body());

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::error('OpenAI Vision API error: '.$e->getMessage(), [
                'exception' => $e,
                'messages' => $messages,
                'image_url' => $imageUrl,
            ]);

            return null;
        }
    }

    /**
     * Transcribe audio file using OpenAI API
     *
     * @param  mixed  $asset  Statamic Asset object
     * @param  string|null  $model  The model to use (default: whisper-1)
     * @return array|null The transcribed text in a response array
     */
    public function transcribe($asset, ?string $model = 'whisper-1'): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');

            return null;
        }

        if (! $asset) {
            Log::error('Asset is null or invalid');

            return null;
        }

        // Use AssetsService to handle the file
        $assetsService = app(AssetsService::class);
        $audioFilePath = $assetsService->getAssetTempPath($asset);

        // Log file information for debugging
        Log::debug('Transcribing audio file', [
            'path' => $audioFilePath,
            'exists' => file_exists($audioFilePath),
            'size' => file_exists($audioFilePath) ? filesize($audioFilePath) : 0,
            'mime' => file_exists($audioFilePath) ? mime_content_type($audioFilePath) : 'unknown',
            'filename' => basename($audioFilePath),
        ]);

        try {
            // Ensure the file handle is valid
            $fileHandle = fopen($audioFilePath, 'r');
            if (! $fileHandle) {
                Log::error('Failed to open audio file for reading');

                return null;
            }

            // Make sure we're using the correct endpoint and parameters
            $response = Http::withToken($this->apiKey)
                ->timeout(60) // Increase timeout for larger files
                ->attach(
                    'file',
                    $fileHandle,
                    basename($audioFilePath)
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model ?? 'whisper-1',
                    'response_format' => 'text',
                ]);

            // Close the file handle
            fclose($fileHandle);

            // Clean up temp file
            $assetsService->cleanupTempFile($audioFilePath);

            if ($response->failed()) {
                Log::error('OpenAI Transcription API error: '.$response->body());

                return null;
            }

            return [
                'text' => $response->body(),
            ];

        } catch (Throwable $e) {
            Log::error('OpenAI Transcription API error: '.$e->getMessage());

            // Clean up temp file in case of error
            $assetsService->cleanupTempFile($audioFilePath);

            return null;
        }
    }

    private function parseResponse(array $response): array
    {
        $content = $response['choices'][0]['message']['content'];

        // clean up the content with regex
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        if (isset($content)) {
            return json_decode($content, true);
        }

        return $response;
    }
}
