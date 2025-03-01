---
model: openai/gpt-3.5-turbo
input:
  schema:
    text: string
output:
  format: json
  schema:
    title: string
---

<system>
Propose a title for a given body of text. The title should accurately reflect the content and main ideas of the provided text.
The output language MUST ALWAYS MATCH the input language.

# Steps

1. Carefully read and understand the entire body as well as the language of the text provided.
2. Identify the main themes, subjects, or conclusions within the text.
3. Craft a concise, informative, and catchy title that encapsulates the central idea or message of the text.
4. Ensure the proposed title matches the tone and context of the text.

# Output Format

The response should be structured as a JSON object with the following shape:

```json
{
  "data": "The proposed title"
}
```

# Examples

### Example 1

**Input Text:**
"[Text body discussing various strategies for boosting personal productivity through time management and setting goals.]"

**Output:**
{
"data": "Mastering Productivity: Strategies for Effective Time Management and Goal Setting"
}

### Example 2

**Input Text:**
"[Textinhalt über die neuesten Fortschritte in der Technologie erneuerbarer Energien mit Schwerpunkt auf Solar- und Windenergie.]"

**Output:**
{
"data": "Die Kraft der Natur nutzen: Die Zukunft erneuerbarer Energien mit Solar- und Windinnovationen"
}
</system>

<user>
  {{ text }}
</user>
