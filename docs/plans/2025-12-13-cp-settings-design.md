# CP Settings Page Design

End-user customization of Magic Actions via Control Panel settings.

## Overview

Allow content editors to customize AI providers, models, and prompts through the Statamic Control Panel without modifying code. Settings are stored in a git-trackable YAML file.

## Goals

1. Global system prompt applied to all actions
2. Per-capability default provider/model selection
3. Per-action overrides for provider, model, and prompts
4. Only show provider/model options that are configured and compatible

## Config Structure Changes

### Providers and Capabilities

Providers hold only credentials. Capabilities define available models and defaults.

```php
// config/statamic/magic-actions.php

'providers' => [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],
],

'capabilities' => [
    'text' => [
        'models' => [
            'openai/gpt-4o',
            'openai/gpt-4o-mini',
            'anthropic/claude-sonnet-4-20250514',
        ],
        'default' => 'openai/gpt-4o',
    ],
    'vision' => [
        'models' => [
            'openai/gpt-4o',
            'anthropic/claude-sonnet-4-20250514',
        ],
        'default' => 'openai/gpt-4o',
    ],
    'audio' => [
        'models' => [
            'openai/whisper-1',
        ],
        'default' => 'openai/whisper-1',
    ],
],

'settings_path' => base_path('content/magic-actions/settings.yaml'),

// fieldtypes config stays as-is
```

Model keys use `provider/model` format for easy parsing: `explode('/', $key)`.

Parameters (temperature, max_tokens) stay on individual action classes via `config()`.

## Action Class Changes

### Interface

```php
interface MagicAction
{
    public function getTitle(): string;
    public function getHandle(): string;

    // NEW: declares what capability this action needs
    public function capability(): string;

    // CHANGED: now optional, only for sparse overrides
    public function config(): array;

    public function system(): string;
    public function prompt(): string;
    public function schema(): ?ObjectSchema;
    public function rules(): array;
    public function unwrap(array $structured): mixed;
    public function icon(): ?string;
}
```

### BaseMagicAction

```php
abstract class BaseMagicAction implements MagicAction
{
    // NEW: abstract, every action must declare
    abstract public function capability(): string;

    // CHANGED: no longer abstract, defaults to empty (no overrides)
    public function config(): array
    {
        return [];
    }

    // ... rest stays the same
}
```

### Example Action (simplified)

```php
final class ExtractTags extends BaseMagicAction
{
    public const string TITLE = 'Extract Tags';

    public function capability(): string
    {
        return 'text';
    }

    // Parameters stay on the action class
    public function config(): array
    {
        return [
            'temperature' => 0.5,
            'max_tokens' => 500,
        ];
    }

    public function schema(): ?ObjectSchema { /* ... */ }
    public function rules(): array { /* ... */ }
    public function system(): string { /* ... */ }
    public function prompt(): string { /* ... */ }
}
```

## Settings Storage

### Settings Class

```php
namespace ElSchneider\StatamicMagicActions;

use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;

class Settings
{
    public static function data(): array
    {
        if (! File::exists(self::path())) {
            return [];
        }

        return YAML::parse(File::get(self::path()));
    }

    public static function save(array $data): void
    {
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), YAML::dump($data));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return data_get(self::data(), $key, $default);
    }

    private static function path(): string
    {
        return config(
            'statamic.magic-actions.settings_path',
            base_path('content/magic-actions/settings.yaml')
        );
    }
}
```

### Settings File Structure

```yaml
# content/magic-actions/settings.yaml

global:
  system_prompt: 'You are an assistant for Acme Corp...'
  defaults:
    text: openai/gpt-4o
    vision: openai/gpt-4o
    audio: openai/whisper-1

actions:
  extract-tags:
    model: null # null = use capability default
    system_prompt: null
    user_prompt: null
  alt-text:
    model: anthropic/claude-sonnet-4-20250514
    system_prompt: 'Custom override...'
    user_prompt: null
```

## CP Settings Page

### Navigation

Tools > Magic Actions (with wand/sparkles icon)

### View Structure

Single page using Statamic's `publish-form` component.

```
+----------------------------------------------------------+
| Magic Actions Settings                                    |
+----------------------------------------------------------+
| Global Settings                                           |
| +------------------------------------------------------+ |
| | System Prompt (textarea)                             | |
| | "Applied to all actions. Describe your brand..."    | |
| +------------------------------------------------------+ |
|                                                          |
| Default Providers                                        |
| +----------------+----------------+----------------+     |
| | Text           | Vision         | Audio          |     |
| | [dropdown]     | [dropdown]     | [dropdown]     |     |
| +----------------+----------------+----------------+     |
+----------------------------------------------------------+
| Action: Extract Tags                                      |
| +------------------------------------------------------+ |
| | Provider: [Use Default] [Model dropdown if set]     | |
| | System Prompt Override: (textarea, empty = default) | |
| | User Prompt Override: (textarea, empty = default)   | |
| +------------------------------------------------------+ |
+----------------------------------------------------------+
| Action: Alt Text                                          |
| +-- ...                                                   |
+----------------------------------------------------------+
```

### Dropdown Logic

- Provider dropdowns only show options with valid API keys AND matching capability
- Model dropdown appears conditionally when a specific provider is selected
- "Use Default" option resolves to global default for that capability

### Implementation Pattern

Following the `duncanmcclean/cookie-notice` addon pattern:

1. **Blueprint class**: Dynamically builds form fields based on registered actions and configured providers
2. **Controller**: Uses Statamic's `publish-form` component with blueprint preprocessing
3. **View**: Extends `statamic::layout`, renders `publish-form` with blueprint/meta/values

## Resolution Order

### Model Resolution

When an action executes, the model is resolved in this order (first non-null wins):

```
1. User action override    -> Settings::get('actions.{handle}.model')
2. User global default     -> Settings::get('global.defaults.{capability}')
3. Config default          -> config('statamic.magic-actions.capabilities.{capability}.default')
```

### Parameters Resolution

Parameters come from the action class only:

```
$action->config()  -> ['temperature' => 0.5, 'max_tokens' => 500]
```

### System Prompt Resolution

```
1. Settings::get('global.system_prompt')           -> prepended to all
2. $action->system()                               -> action default
3. Settings::get('actions.{handle}.system_prompt') -> replaces #2 if set
```

### ActionLoader Changes

```php
$capability = $action->capability();
$handle = $action->getHandle();

// Resolve model (first non-null wins)
$model = Settings::get("actions.{$handle}.model")
    ?? Settings::get("global.defaults.{$capability}")
    ?? config("statamic.magic-actions.capabilities.{$capability}.default");

// Parse provider/model
[$provider, $modelName] = explode('/', $model);

// Parameters from action class
$parameters = $action->config();

// Assemble system prompt
$globalSystemPrompt = Settings::get('global.system_prompt', '');
$actionSystemPrompt = Settings::get("actions.{$handle}.system_prompt")
    ?? $action->system();

$systemPrompt = trim("{$globalSystemPrompt}\n\n{$actionSystemPrompt}");
```

## Files to Create/Modify

### New Files

- `src/Settings.php` - Settings storage class
- `src/Settings/Blueprint.php` - Dynamic blueprint builder
- `src/Http/Controllers/CP/SettingsController.php` - CP controller
- `resources/views/cp/settings.blade.php` - Settings view
- `routes/cp.php` - CP routes

### Modified Files

- `src/Contracts/MagicAction.php` - Add `capability()` method
- `src/MagicActions/BaseMagicAction.php` - Add abstract `capability()`, make `config()` non-abstract
- `src/MagicActions/*.php` - Add `capability()` to each action, simplify `config()`
- `src/Services/ActionLoader.php` - Implement resolution order
- `src/ServiceProvider.php` - Register routes, nav item
- `config/statamic/magic-actions.php` - Restructure to providers (credentials) + capabilities (models, defaults)

## Future: Dynamic Context Injection (Feature #2)

This design focuses on end-user prompt customization. A separate feature will address:

- Server-side variable injection (e.g., `Term::all()` for available tags)
- Dynamic schema generation with `EnumSchema` constraints
- Context passed to `schema()` and prompt templates at runtime

These concerns are independent and will be designed separately.
