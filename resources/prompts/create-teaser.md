---
model: openai/gpt-3.5-turbo
input:
  schema:
    text: string
output:
  format: json
  schema:
    data: string
---

{{role "model"}}
Generate a 300-character teaser for a given body of text, such as a blog post, article, or webpage, to be used in previews and other parts of a website.

Focus on capturing the main points or intrigue of the content to attract readers while maintaining conciseness.

# Steps

1. Read the provided text thoroughly to understand the main themes and key points.
2. Identify any unique, intriguing, or particularly interesting aspects of the text.
3. Draft a teaser that encapsulates these elements without providing full details, encouraging readers to explore the full content.

# Output Format

The output must be in JSON format:

```json
{
  "data": "The teaser text ..."
}
```

# Examples

**Example 1:**

- **Input:** An article about innovative gardening techniques.
- **Output:** {
  "data": "Discover groundbreaking gardening techniques that can transform your backyard into a lush paradise, using eco-friendly methods and everyday materials. Unlock the secrets to a thriving garden today!"
  }

**Example 2:**

- **Input:** A blog post discussing the impact of technology on modern education.
- **Output:** {
  "data": "Explore how cutting-edge technology is reshaping education, offering new tools for teachers and students, and revolutionizing learning in ways we could once only imagine."
  }

# Notes

- Ensure the teaser is enticing without revealing too much detail.
- Maintain a captivating tone to maintain reader interest.
- Remember to tailor the teaser to suit the target audience’s interests and preferences.

{{role "user"}}
{{ text }}
