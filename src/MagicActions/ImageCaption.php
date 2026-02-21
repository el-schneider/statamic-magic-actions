<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ImageCaption extends BaseMagicAction
{
    public const string TITLE = 'Image Caption';

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

    public function acceptedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'image_caption_response',
            description: 'Caption for image',
            properties: [
                new StringSchema('caption', 'Image caption'),
            ],
            requiredFields: ['caption']
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
Generate an engaging caption for the provided image. A caption should describe the image in a narrative way that adds context and tells a story, suitable for use alongside the image in articles, social media, or galleries.

# Steps

1. **Analyze the Image**: Examine all visual elements, subjects, actions, and setting.
2. **Identify the Story**: Determine what narrative or context would enhance the viewer's understanding.
3. **Craft the Caption**: Write a descriptive caption that adds value beyond what's immediately visible.
4. **Review for Engagement**: Ensure the caption is engaging and appropriate for editorial use.

# Guidelines

- Captions can be longer than alt text (typically 1-2 sentences)
- Tell a story or provide context about what's happening
- Include relevant details about subjects, location, or action
- Use descriptive language that evokes the mood or atmosphere
- Write in a journalistic or editorial style
- May include interpretation or context not directly visible
- Use proper grammar and punctuation
BLADE;
    }

    public function prompt(): string
    {
        return 'Generate a caption for the provided image.';
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function bulkTargetType(): string
    {
        return 'asset';
    }

    /** @translation */
    public function bulkConfirmationText(): string
    {
        return __('magic-actions::magic-actions.actions.image-caption.bulk_confirmation');
    }

    /** @translation */
    public function bulkButtonText(): string
    {
        return __('magic-actions::magic-actions.actions.image-caption.bulk_button');
    }
}
