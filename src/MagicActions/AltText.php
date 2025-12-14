<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class AltText extends BaseMagicAction
{
    public const string TITLE = 'Alt Text';

    public function type(): string
    {
        return 'vision';
    }

    public function parameters(): array
    {
        return [
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'alt_text_response',
            description: 'Alt text description for image',
            properties: [
                new StringSchema('alt_text', 'Alt text description'),
            ],
            requiredFields: ['alt_text']
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
Generate a high-quality alt text description for the provided image. Alt text should be concise, descriptive, and convey the important visual information for users who cannot see the image.

# Steps

1. **Analyze the Image**: Carefully examine all important visual elements.
2. **Identify Key Content**: Determine what's most important about this image in context.
3. **Create Concise Description**: Write a brief but descriptive alt text that captures the essence of the image.
4. **Review for Accessibility**: Ensure the alt text serves its primary purpose of providing equivalent information to visually impaired users.

# Guidelines

- Keep alt text concise (typically 125 characters or less) but descriptive
- Focus on the most important visual information
- Include relevant context and purpose of the image
- Don't begin with phrases like "Image of" or "Picture of"
- Use proper grammar and punctuation
- If image contains text, include that text in the description
- Consider the context of where the image will be used
BLADE;
    }

    public function prompt(): string
    {
        return 'Analyze the provided image.';
    }
}
