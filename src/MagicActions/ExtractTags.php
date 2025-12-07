<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ExtractTags extends BaseMagicAction
{
    public const string TITLE = 'Extract Tags';

    public function config(): array
    {
        return [
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.5,
                'max_tokens' => 500,
            ],
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'tags_response',
            description: 'Extracted tags from content',
            properties: [
                new ArraySchema('tags', 'Array of tag strings', new StringSchema('tag', 'A single tag')),
            ],
            requiredFields: ['tags']
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
You are a content tagging expert. Extract relevant, concise tags from the provided content.

# Requirements

- Tags should be single words or short phrases
- Return 3-7 tags maximum
- Tags should be lowercase
- Avoid generic terms
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}
BLADE;
    }
}
