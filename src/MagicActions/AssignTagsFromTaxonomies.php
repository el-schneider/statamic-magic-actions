<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class AssignTagsFromTaxonomies extends BaseMagicAction
{
    public const string TITLE = 'Assign Tags from Taxonomies';

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
            name: 'assigned_tags_response',
            description: 'Tags assigned from available taxonomy',
            properties: [
                new ArraySchema('tags', 'Array of selected tag strings', new StringSchema('tag', 'A single tag from the taxonomy')),
            ],
            requiredFields: ['tags']
        );
    }

    public function rules(): array
    {
        return [
            'content' => 'required|string',
            'available_tags' => 'required|string',
        ];
    }

    public function system(): string
    {
        return <<<'BLADE'
You are a content classification expert. Your task is to select the most appropriate tags from a provided taxonomy list and assign them to the given content.

# Requirements

- **Only use tags from the provided taxonomy** - do not create or suggest new tags
- Select tags by carefully reviewing the available taxonomy options
- Choose 3-7 tags that best match the content
- Tags should be relevant and specific to the content
- Return tags exactly as they appear in the provided taxonomy list
- If the taxonomy has limited options, work within those constraints
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
Content:
{{ $content }}

Available Tags:
{{ $available_tags }}
BLADE;
    }
}
