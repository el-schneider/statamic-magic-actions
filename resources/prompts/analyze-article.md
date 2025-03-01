---
model: openai/gpt-4o
input:
  schema:
    article_text: string
    source_url: string
    language: string
output:
  format: json
  validation:
    title: 'required|string|min:5|max:100'
    summary: 'required|string|min:100|max:500'
    language: 'required|string|in:en,de,fr,es,it'
    sentiment_score: 'required|numeric|min:-1|max:1'
    keywords: 'required|array|min:3|max:10'
    'keywords.*': 'required|string|min:2|max:30'
    entities:
      rules: 'required|array'
      items:
        rules: 'required|array'
      properties:
        people: 'array'
        'people.*': 'string'
        organizations: 'array'
        'organizations.*': 'string'
        locations: 'array'
        'locations.*': 'string'
    metadata:
      rules: 'required|array'
      properties:
        reading_time_minutes: 'required|integer|min:1'
        complexity_level: 'required|string|in:basic,intermediate,advanced'
        topic_category: 'required|string'
---

<system>
Analyze the provided article text and extract key information, including a title, summary, sentiment, keywords, and named entities.

Your analysis should be thorough and capture the essence of the article. You'll analyze text in various languages, so be prepared to work with content beyond English.

## Output Format

Return a JSON object with the following structure:

```json
{
  "title": "Concise title for the article",
  "summary": "A comprehensive 1-2 paragraph summary of the key points",
  "language": "en", // Language code: en, de, fr, es, it
  "sentiment_score": 0.75, // Range from -1 (very negative) to 1 (very positive)
  "keywords": ["relevant", "keywords", "from", "the", "text"],
  "entities": {
    "people": ["Person Name 1", "Person Name 2"],
    "organizations": ["Organization 1", "Organization 2"],
    "locations": ["Location 1", "Location 2"]
  },
  "metadata": {
    "reading_time_minutes": 5,
    "complexity_level": "intermediate", // basic, intermediate, or advanced
    "topic_category": "Technology" // Your assessment of the general topic
  }
}
```

## Guidelines

1. The title should be concise but descriptive
2. The summary should capture the main points without being overly detailed
3. Keywords should be truly relevant and reflect the core concepts
4. Sentiment score should reflect the emotional tone accurately
5. Identify real entities mentioned in the text - leave arrays empty if none are found
6. Make reasonable estimates for reading time and complexity level
</system>

<user>
Article text: {{ article_text }}
Source URL: {{ source_url }}
Language: {{ language }}
</user>
