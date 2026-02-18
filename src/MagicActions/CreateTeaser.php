<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class CreateTeaser extends BaseMagicAction
{
    public const string TITLE = 'Create Teaser';

    public function type(): string
    {
        return 'text';
    }

    public function parameters(): array
    {
        return [
            'temperature' => 0.8,
            'max_tokens' => 500,
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'teaser_response',
            description: 'Generated teaser text for content preview',
            properties: [
                new StringSchema('teaser', 'Teaser text (approximately 300 characters)'),
            ],
            requiredFields: ['teaser']
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
Generate a 300-character teaser for a given body of text, such as a blog post, article, or webpage, to be used in previews and other parts of a website.
The output language MUST ALWAYS MATCH the input language.

Focus on capturing the main points or intrigue of the content to attract readers while maintaining conciseness.

# Steps

1. Read the provided text thoroughly to understand the main themes and key points as well as language.
2. Identify any unique, intriguing, or particularly interesting aspects of the text.
3. Draft a teaser that encapsulates these elements without providing full details, encouraging readers to explore the full content.

# Output Format

The teaser should be approximately 300 characters or less.

# Examples

**Example 1:**

- **Input:** [An article about innovative gardening techniques.]
- **Output:** "Discover groundbreaking gardening techniques that can transform your backyard into a lush paradise, using eco-friendly methods and everyday materials. Unlock the secrets to a thriving garden today!"

**Example 2:**

- **Input:** [Ein Blogbeitrag über die Auswirkungen der Technologie auf die moderne Bildung.]
- **Output:** "Entdecken Sie, wie modernste Technologie die Bildung neu gestaltet, neue Werkzeuge für Lehrer und Schüler bietet und das Lernen auf bisher unvorstellbare Weise revolutioniert."

# Notes

- Ensure the teaser is enticing without revealing too much detail.
- Maintain a captivating tone to maintain reader interest.
- Remember to tailor the teaser to suit the target audience's interests and preferences.
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}
BLADE;
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function bulkTargetType(): string
    {
        return 'entry';
    }

    public function bulkConfirmationText(): string
    {
        return __('magic-actions::messages.confirm_create_teaser');
    }

    public function bulkButtonText(): string
    {
        return __('magic-actions::messages.button_create_teaser');
    }
}
