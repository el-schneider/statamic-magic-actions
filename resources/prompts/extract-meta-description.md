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

{{role "model"}}
Create a keyword-optimized meta description from a provided body of text, such as a blog entry, article, or webpage that describes a service or company. The description must never exceed 160 characters and should be formatted as specified.

Identify key phrases and concepts within the text that would increase search engine visibility. Craft a concise and compelling summary that reflects the text's content while including important keywords.

# Steps

1. **Analyze Text:** Read the provided text carefully, identifying the main topic and purpose.
2. **Extract Keywords:** Identify and list potential keywords and key phrases that are relevant to SEO.
3. **Compose Meta Description:** Write a concise, compelling summary that integrates the identified keywords. Ensure it provides a clear and accurate representation of the content.
4. **Evaluate SEO Effectiveness:** Check that the meta description is appealing, contains action-oriented language, and includes a call to action if applicable.

# Output Format

Generate a JSON object structured as:
```json
{
  "data": "The proposed title"
}
```
- The description must be a single sentence or two, with a maximum of 160 characters.
- It should include identified keywords.
- Designed to attract clicks and enhance SEO.
- The output language MUST MATCH the input language.

# Examples

**Example 1:**
- **Input:** A blog post discussing the benefits of organic gardening.
- **Output:**
{
  "data": "Discover organic gardening benefits: boost health, save costs, sustain life."
}

**Example 2:**
- **Input:** Ein Artikel über die Bedeutung der Cybersicherheit in kleinen Unternehmen.
- **Output:**
{
  "data": "Schützen Sie kleine Unternehmen: Wichtige Cybersicherheitsstrategien sichern Daten und Vertrauen."
}

(Note: Outputs should be up to 160 characters and integrate important keywords from the content.)

{{role "user"}}
{{ text }}
