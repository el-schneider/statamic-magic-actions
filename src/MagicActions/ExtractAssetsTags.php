<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ExtractAssetsTags extends BaseMagicAction
{
    public const string TITLE = 'Extract Tags';

    public function type(): string
    {
        return 'vision';
    }

    public function parameters(): array
    {
        return [
            'temperature' => 0.5,
            'max_tokens' => 500,
        ];
    }

    public function acceptedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'asset_tags_response',
            description: 'Tags extracted from image asset',
            properties: [
                new ArraySchema('tags', 'Array of image tag strings', new StringSchema('tag', 'A single descriptive tag')),
            ],
            requiredFields: ['tags']
        );
    }

    public function rules(): array
    {
        return [
            'text' => 'sometimes|string',
        ];
    }

    public function system(): string
    {
        return <<<'BLADE'
Generate highly relevant tags for the uploaded image. The generated tags should accurately reflect the main visual elements, themes, subjects, and features visible in the image.

# Steps

1. **Analyze the Image**: Carefully examine all visual elements in the provided image.
2. **Identify Key Elements**: Identify objects, people, scenes, colors, moods, activities, and other notable visual elements.
3. **Generate Tags**: Create concise and relevant tags that describe what's in the image.
4. **Refinement**: Ensure tags are specific, relevant, and accurately represent the image content.

# Output Format

- Return 5-15 specific, relevant tags as an array
- Include both specific objects and broader contextual tags
- Consider both concrete elements (people, objects) and abstract qualities (mood, style)
- If the image contains text, include relevant keywords from that text
- Maintain appropriate language and tone
- Sort tags from most to least relevant
BLADE;
    }

    public function prompt(): string
    {
        return 'Analyze the provided image.';
    }
}
