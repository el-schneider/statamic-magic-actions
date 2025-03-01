# Output Validation Format

This document explains how to define validation rules for AI-generated outputs in your Magic Actions prompt files.

## Basic Syntax

The validation rules are defined in the YAML frontmatter of your prompt file, under the `output.validation` key. The rules directly map to Laravel's validation rules.

```yaml
---
model: openai/gpt-4o
output:
  format: json
  validation:
    field_name: 'required|string|max:100'
    another_field: 'required|numeric|min:1|max:100'
---
```

## Validation Formats

You can define validation rules using two different formats:

### 1. Simple Format

The simplest format uses direct Laravel validation rule strings:

```yaml
validation:
  title: 'required|string|min:5|max:100'
  body: 'required|string'
  tags: 'required|array|min:1'
  'tags.*': 'string|min:2'
```

### 2. Expanded Format

For more complex structures, you can use an expanded format with nested properties:

```yaml
validation:
  user:
    rules: 'required|array'
    properties:
      name: 'required|string'
      email: 'required|email'
  items:
    rules: 'required|array'
    items:
      rules: 'required|array'
      properties:
        id: 'required|integer'
        name: 'required|string'
```

## Common Validation Rules

Here are some commonly used Laravel validation rules:

| Rule             | Description                             |
| ---------------- | --------------------------------------- |
| `required`       | Field must be present and not empty     |
| `string`         | Field must be a string                  |
| `numeric`        | Field must be numeric                   |
| `integer`        | Field must be an integer                |
| `array`          | Field must be an array                  |
| `boolean`        | Field must be a boolean                 |
| `min:value`      | Minimum value/length                    |
| `max:value`      | Maximum value/length                    |
| `email`          | Must be a valid email address           |
| `url`            | Must be a valid URL                     |
| `date`           | Must be a valid date                    |
| `in:foo,bar,...` | Value must be one of the listed options |
| `json`           | Must be valid JSON                      |

For a complete list of validation rules, refer to the [Laravel documentation](https://laravel.com/docs/11.x/validation#available-validation-rules).

## Array Validation

To validate array items, use the `*` wildcard:

```yaml
validation:
  tags: 'required|array|min:3|max:10'
  'tags.*': 'required|string|min:2|max:30'
```

## Nested Object Validation

For nested objects, you can use dot notation:

```yaml
validation:
  'user.name': 'required|string'
  'user.email': 'required|email'
```

Or use the expanded format:

```yaml
validation:
  user:
    rules: 'required|array'
    properties:
      name: 'required|string'
      email: 'required|email'
```

## Using Validation in Code

You can validate AI responses in your code using the `validateResponse` method:

```php
$promptsService = app(PromptsService::class);

try {
    // Will throw ValidationException if validation fails
    $promptsService->validateResponse('my-prompt', $aiResponse);

    // Continue processing with valid response
    // ...
} catch (ValidationException $e) {
    // Handle validation errors
    $errors = $e->errors();
    // ...
}

// Alternatively, get errors without throwing
$validationResult = $promptsService->validateResponse('my-prompt', $aiResponse, false);
if ($validationResult !== true) {
    // $validationResult contains validation errors
}
```
