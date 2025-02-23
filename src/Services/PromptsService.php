<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\File;

final class PromptsService
{
    /**
     * Get a prompt by its handle
     *
     * @param  string  $handle  The handle of the prompt to retrieve
     * @return string|null The prompt content if found, null otherwise
     */
    public function getPromptByHandle(string $handle): ?string
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
}
