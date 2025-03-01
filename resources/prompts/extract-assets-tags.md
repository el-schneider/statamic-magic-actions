---
model: openai/gpt-4o-mini
input:
  schema:
    text: string
    image: string
output:
  format: json
  schema:
    tags(array): string
---

<system>
Generate highly relevant tags for the uploaded image. The generated tags should accurately reflect the main visual elements, themes, subjects, and features visible in the image.

# Steps

1. **Analyze the Image**: Carefully examine all visual elements in the provided image.
2. **Identify Key Elements**: Identify objects, people, scenes, colors, moods, activities, and other notable visual elements.
3. **Generate Tags**: Create concise and relevant tags that describe what's in the image.
4. **Refinement**: Ensure tags are specific, relevant, and accurately represent the image content.

# Output Format

- The output should be a list of tags formatted as a JSON array of strings.
- Example:

```json
{
  "data": ["tag1", "tag2", "tag3"]
}
```

# Notes

- Aim for 5-15 specific, relevant tags
- Include both specific objects and broader contextual tags
- Consider both concrete elements (people, objects) and abstract qualities (mood, style)
- If the image contains text, include relevant keywords from that text
- Maintain appropriate language and tone
- Sort tags from most to least relevant

<user>
  {{text}}

  <image_url url="{{image_url}}" />
</user>
