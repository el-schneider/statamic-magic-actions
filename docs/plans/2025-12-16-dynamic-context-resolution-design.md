# Dynamic Context Resolution for MagicActions

## Problem

MagicActions like `ExtractTags` need to constrain AI responses to existing data (e.g., taxonomy terms). Currently, there's no way for an action to dynamically fetch and inject context into blade templates.

## Solution

Add two methods to `BaseMagicAction` that allow actions to:
1. Define a toggle that controls whether context resolution is enabled
2. Resolve additional context variables when the toggle is on

## Design

### New Methods on BaseMagicAction

```php
/**
 * Define a toggle that controls whether resolveContext() is called.
 * Return null if this action doesn't need a toggle.
 */
public function contextToggle(): ?array
{
    return null;
}

/**
 * Resolve additional context to pass to blade templates.
 * Only called when contextToggle() returns config AND the toggle is enabled.
 *
 * @param array $context ['type' => 'entries', 'id' => '...', 'field' => 'tags']
 * @param array $fieldConfig The field's blueprint configuration
 * @return array Additional variables to merge into blade context
 */
public function resolveContext(array $context, array $fieldConfig): array
{
    return [];
}
```

### Example: ExtractTags with Taxonomy Constraint

```php
final class ExtractTags extends BaseMagicAction
{
    public function contextToggle(): ?array
    {
        return [
            'display' => 'Use existing',
            'default' => true,
            'instructions' => 'When enabled, constrains results to existing taxonomy terms.',
        ];
    }

    public function resolveContext(array $context, array $fieldConfig): array
    {
        $terms = Term::query()
            ->where('taxonomy', 'tags')
            ->get()
            ->map(fn ($term) => $term->title())
            ->join(', ');

        return ['available_tags' => $terms];
    }

    public function rules(): array
    {
        return [
            'text' => 'required|string',
            'available_tags' => 'sometimes|string',  // Optional when toggle is off
        ];
    }

    public function prompt(): string
    {
        return <<<'BLADE'
{{ $text }}

@isset($available_tags)
Available Tags:
{{ $available_tags }}
@endisset
BLADE;
    }
}
```

### Field Config Toggle

Key: `magic_actions_resolve_context`

The toggle only appears in the field config when:
1. The selected action implements `contextToggle()` (returns non-null)
2. That specific action is currently selected

```php
// Generated dynamically in FieldConfigService
'magic_actions_resolve_context' => [
    'type' => 'toggle',
    'display' => 'Use existing',  // From action's contextToggle()
    'instructions' => 'When enabled, constrains results to existing taxonomy terms.',
    'default' => true,
    'if' => [
        'magic_actions_enabled' => true,
        'magic_actions_action' => 'extract-tags',  // Only for this action
    ],
],
```

### Backend Resolution Flow

1. Frontend sends existing payload (no changes needed):
   ```json
   {
     "text": "...",
     "action": "extract-tags",
     "context_type": "entries",
     "context_id": "abc-123",
     "field_handle": "tags"
   }
   ```

2. Backend resolves field config from blueprint:
   ```php
   $item = $this->resolveItem($context['type'], $context['id']);
   $blueprint = $item->blueprint();
   $fieldConfig = $blueprint->field($context['field'])->config();
   ```

3. Check toggle and resolve context:
   ```php
   if ($action->contextToggle() && ($fieldConfig['magic_actions_resolve_context'] ?? false)) {
       $extraContext = $action->resolveContext($context, $fieldConfig);
       $variables = array_merge($variables, $extraContext);
   }
   ```

4. `ActionLoader::load()` validates merged variables and renders templates

## Toggle Semantics

- `magic_actions_resolve_context = true` → Call `resolveContext()`, constrain to existing items
- `magic_actions_resolve_context = false` → Don't call, AI can suggest anything

## Validation Strategy

Actions that use `resolveContext()` should:

1. Make context-dependent fields optional in `rules()`:
   ```php
   'available_tags' => 'sometimes|string',
   ```

2. Use `@isset` in blade templates:
   ```blade
   @isset($available_tags)
   Available Tags:
   {{ $available_tags }}
   @endisset
   ```

## Files to Modify

- `src/MagicActions/BaseMagicAction.php` - Add default method implementations
- `src/Contracts/MagicAction.php` - Add method signatures to interface
- `src/Services/FieldConfigService.php` - Generate dynamic toggle fields per-action
- `src/Jobs/ProcessPromptJob.php` - Resolve blueprint config and call `resolveContext()`
- `src/MagicActions/ExtractTags.php` - Implement the new methods
