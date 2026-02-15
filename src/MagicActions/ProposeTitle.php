<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ProposeTitle extends BaseMagicAction
{
    public const string TITLE = 'Propose Title';

    public function type(): string
    {
        return 'text';
    }

    public function parameters(): array
    {
        return [
            'temperature' => 0.7,
            'max_tokens' => 200,
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
        return 'Propose a title for this entry?|Propose titles for these :count entries?';
    }

    public function bulkButtonText(): string
    {
        return 'Propose Title|Propose Titles for :count Entries';
    }
}
