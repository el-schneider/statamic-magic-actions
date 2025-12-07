<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ExtractMetaDescription extends BaseMagicAction
{
    public const string TITLE = 'Extract Meta Description';
    public const string HANDLE = 'extract-meta-description';

    public function config(): array
    {
        return [
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'parameters' => [
                'temperature' => 0.7,
                'max_tokens' => 300,
            ],
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return new ObjectSchema(
            name: 'meta_description_response',
            description: 'SEO-optimized meta description for content',
            properties: [
                new StringSchema('description', 'Meta description (max 160 characters)'),
            ],
            requiredFields: ['description']
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
Create a keyword-optimized meta description from a provided body of text, such as a blog entry, article, or webpage that describes a service or company. The description must never exceed 160 characters and should be formatted as specified.

Identify key phrases and concepts within the text that would increase search engine visibility. Craft a concise and compelling summary that reflects the text's content while including important keywords.

# Steps

1. **Analyze Text:** Read the provided text carefully, identifying the main topic and purpose as well as language.
2. **Extract Keywords:** Identify and list potential keywords and key phrases that are relevant to SEO.
3. **Compose Meta Description:** Write a concise, compelling summary that integrates the identified keywords. Ensure it provides a clear and accurate representation of the content.
4. **Evaluate SEO Effectiveness:** Check that the meta description is appealing, contains action-oriented language, and includes a call to action if applicable.

# Output Format

- The description must be a single sentence or two, with a maximum of 160 characters.
- It should include identified keywords.
- Designed to attract clicks and enhance SEO.
- The output language MUST MATCH the input language.

# Examples

**Example 1:**

- **Input:** A blog post discussing the benefits of organic gardening.
- **Output:** "Discover organic gardening benefits: boost health, save costs, sustain life."

**Example 2:**

- **Input:** Ein Artikel über die Bedeutung der Cybersicherheit in kleinen Unternehmen.
- **Output:** "Schützen Sie kleine Unternehmen: Wichtige Cybersicherheitsstrategien sichern Daten und Vertrauen."

(Note: Outputs should be up to 160 characters and integrate important keywords from the content.)
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}
BLADE;
    }
}
