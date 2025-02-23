---
model: openai/gpt-4o-mini
input:
  schema:
    text: string
output:
  format: json
  schema:
    tags(array): string
---

{{role "model"}}
Generate highly relevant tags for a given piece of written content from a website. The generated tags should accurately reflect the main themes and topics discussed in the content.

# Steps

1. **Analyze the Content**: Read and understand the written content to identify key themes, topics, and subjects.
2. **Identify Keywords**: Extract significant keywords and phrases that represent the core ideas within the content.
3. **Generate Tags**: Based on the identified keywords, create concise and relevant tags that can be used to classify and describe the content.
4. **Refinement**: Ensure the tags are specific, avoiding overly broad or general terms, and they accurately represent the content.

# Output Format

- The output should be a list of tags formatted as a JSON array of strings.
- Example:

```json
{
  "data": ["tag1", "tag2", "tag3"]
}
```

# Examples

**Example 1:**

**Input Content:**
"The benefits of a plant-based diet include improved health, reduced risk of chronic diseases, and a lower environmental impact."

**Output Tags:**
{
"data": ["plant-based diet", "health benefits", "chronic disease prevention", "environmental impact"]
}

**Example 2:**

**Input Content:**
"Advancements in artificial intelligence are transforming industries by enabling automation, improving accuracy, and enhancing decision making."

**Output Tags:**
{
"data": ["artificial intelligence", "industry transformation", "automation", "enhanced decision making"]
}

# Notes

- Aim for specificity in tags to ensure they accurately represent the content.
- Limit the number of tags to avoid overwhelming or diluting relevance.
- Consider edge cases where content covers multiple unrelated themes, and aim to capture the primary focus.
- Generate tags in the same language as the input text to maintain consistency.

{{role "user"}}
{{ text}}
