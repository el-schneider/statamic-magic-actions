<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ProposeTitle implements MagicAction
{
    public function getTitle(): string
    {
        return 'Propose Title';
    }

    public function getHandle(): string
    {
        return 'propose-title';
    }

    public function config(): array
    {
        return [
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.7,
                'max_tokens' => 200,
            ],
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'title_response',
            description: 'Proposed title for content',
            properties: [
                new StringSchema('title', 'Proposed title'),
            ],
            requiredFields: ['title']
        );
    }

    public function rules(): array
    {
        return [
            'text' => 'required|string',
        ];
    }

    public function system(): string
    {
        return <<<'BLADE'
You are a content expert. Generate compelling, SEO-friendly titles for web content.

# Requirements

- Titles should be 50-60 characters for optimal display
- Use power words when appropriate
- Make it descriptive and engaging
- Avoid clickbait
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}
BLADE;
    }
}
