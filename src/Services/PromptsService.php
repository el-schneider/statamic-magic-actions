<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final class PromptsService
{
    private PromptParserService $promptParser;

    public function __construct(PromptParserService $promptParser)
    {
        $this->promptParser = $promptParser;
    }

    /**
     * Get a prompt content by its handle
     *
     * @param  string  $handle  The handle of the prompt to retrieve
     * @return string|null The prompt content if found, null otherwise
     */
    public function getPromptContent(string $handle): ?string
    {
        $publishedPromptPath = resource_path('prompts/'.$handle.'.md');
        $addonPromptPath = dirname(__DIR__, 2).'/resources/prompts/'.$handle.'.md';

        if (File::exists($publishedPromptPath)) {
            return File::get($publishedPromptPath);
        }

        if (File::exists($addonPromptPath)) {
            return File::get($addonPromptPath);
        }

        return null;
    }

    /**
     * Get a parsed prompt by its handle
     */
    public function getParsedPrompt(string $handle): ?array
    {
        // Use caching to avoid parsing the same prompt multiple times
        return Cache::remember('magic_actions_prompt_'.$handle, 60, function () use ($handle) {
            $content = $this->getPromptContent($handle);

            if (! $content) {
                return null;
            }

            return $this->promptParser->parse($content);
        });
    }

    /**
     * Get a parsed prompt with variables rendered
     */
    public function getParsedPromptWithVariables(string $handle, array $variables): ?array
    {
        $promptData = $this->getParsedPrompt($handle);

        if (! $promptData) {
            return null;
        }

        return $this->promptParser->renderVariables($promptData, $variables);
    }

    /**
     * Validate an AI response against the validation rules defined in the prompt
     *
     * @param  string  $handle  The prompt handle
     * @param  mixed  $response  The response data to validate
     * @param  bool  $throwOnFailure  Whether to throw an exception on validation failure
     * @return array Returns the validated response data
     *
     * @throws ValidationException When validation fails and $throwOnFailure is true
     */
    public function validateResponse(string $handle, mixed $response, bool $throwOnFailure = true): array
    {
        $promptData = $this->getParsedPrompt($handle);

        if (! $promptData || ! isset($promptData['validation_rules'])) {
            // No validation rules found, consider it valid
            return $response;
        }

        $data = $response;

        // If response is a string, try to decode it as JSON
        if (is_string($response)) {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            } catch (JsonException $e) {
                // Not valid JSON, keep original string
            }
        }

        // Create validator instance
        $validator = Validator::make($data, $promptData['validation_rules']);

        // Check if validation passes
        if ($validator->passes()) {
            return $data;
        }

        // Validation failed
        if ($throwOnFailure) {
            throw new ValidationException($validator);
        }

        return $validator->errors()->toArray();
    }

    /**
     * Check if a prompt exists
     */
    public function promptExists(string $handle): bool
    {
        $publishedPromptPath = resource_path('prompts/'.$handle.'.md');
        $addonPromptPath = dirname(__DIR__, 2).'/resources/prompts/'.$handle.'.md';

        return File::exists($publishedPromptPath) || File::exists($addonPromptPath);
    }

    /**
     * List all available prompts
     */
    public function getAllPrompts(): array
    {
        $addonPrompts = collect(File::files(dirname(__DIR__, 2).'/resources/prompts/'))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->map(fn ($file) => $file->getFilenameWithoutExtension());

        $publishedPrompts = collect();

        if (File::exists(resource_path('prompts'))) {
            $publishedPrompts = collect(File::files(resource_path('prompts')))
                ->filter(fn ($file) => $file->getExtension() === 'md')
                ->map(fn ($file) => $file->getFilenameWithoutExtension());
        }

        return $addonPrompts->merge($publishedPrompts)->unique()->toArray();
    }
}
