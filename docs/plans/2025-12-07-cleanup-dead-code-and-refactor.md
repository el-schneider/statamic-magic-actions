# Dead Code Removal & ServiceProvider Refactoring Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Remove dead code, refactor ServiceProvider's complex bootAddon() method, and consolidate duplicate action resolution logic.

**Architecture:**
- Remove 4 unused/orphaned code artifacts (PromptsService, AssetsService, OpenAIApiException, markdown prompts)
- Extract ServiceProvider's 84-line bootAddon() into focused private methods for clarity and testability
- Create ActionRegistry service to centralize action metadata and eliminate duplication across codebase
- Consolidate all class-to-handle conversions to single location

**Tech Stack:** PHP 8.1+, Laravel service provider pattern, PHPUnit tests

---

## Task 1: Delete PromptsService and PromptParserService references

**Files:**
- Delete: `src/Services/PromptsService.php`
- Modify: `src/ServiceProvider.php:37` (remove singleton registration)
- Verify: No other files reference `PromptsService`

**Step 1: Verify PromptsService is not used**

Run:
```bash
grep -r "PromptsService" src tests resources --include="*.php" --include="*.ts" --include="*.vue"
```

Expected: Only results are `src/Services/PromptsService.php` and `src/ServiceProvider.php:37`

**Step 2: Remove PromptsService singleton from ServiceProvider**

Edit `src/ServiceProvider.php` line 37:

Current:
```php
$this->app->singleton(PromptsService::class);
```

Delete this entire line.

**Step 3: Delete PromptsService.php file**

Run:
```bash
rm src/Services/PromptsService.php
```

**Step 4: Verify no broken imports**

Run tests to ensure no import errors:
```bash
./vendor/bin/pest --filter=Prompt
```

Expected: Either zero tests (ok) or passing tests.

**Step 5: Commit**

```bash
git add src/ServiceProvider.php
git rm src/Services/PromptsService.php
git commit -m "remove: delete unused PromptsService and orphaned markdown prompt system"
```

---

## Task 2: Delete AssetsService

**Files:**
- Delete: `src/Services/AssetsService.php`
- Verify: Not registered anywhere, not imported anywhere

**Step 1: Verify AssetsService is not registered or used**

Run:
```bash
grep -r "AssetsService" src tests resources --include="*.php" --include="*.ts" --include="*.vue"
```

Expected: Only result is `src/Services/AssetsService.php`

**Step 2: Delete AssetsService.php**

Run:
```bash
rm src/Services/AssetsService.php
```

**Step 3: Commit**

```bash
git rm src/Services/AssetsService.php
git commit -m "remove: delete unused AssetsService"
```

---

## Task 3: Delete OpenAIApiException

**Files:**
- Delete: `src/Exceptions/OpenAIApiException.php`
- Verify: Not thrown or caught anywhere

**Step 1: Verify exception is not used**

Run:
```bash
grep -r "OpenAIApiException" src tests resources --include="*.php" --include="*.ts" --include="*.vue"
```

Expected: Only result is `src/Exceptions/OpenAIApiException.php`

**Step 2: Delete OpenAIApiException.php**

Run:
```bash
rm src/Exceptions/OpenAIApiException.php
```

**Step 3: Commit**

```bash
git rm src/Exceptions/OpenAIApiException.php
git commit -m "remove: delete unused OpenAIApiException"
```

---

## Task 4: Delete orphaned markdown prompt files

**Files:**
- Delete: `resources/prompts/*.md` (8 files)

**Step 1: List markdown prompt files**

Run:
```bash
ls -la resources/prompts/
```

Expected output shows: `alt-text.md`, `analyze-article.md`, `assign-tags-from-taxonomy.md`, `create-teaser.md`, `extract-assets-tags.md`, `extract-meta-description.md`, `extract-tags.md`, `propose-title.md`, `transcribe-audio.md`

**Step 2: Verify no code references these files**

Run:
```bash
grep -r "resources/prompts" src tests resources --include="*.php" --include="*.ts" --include="*.vue"
grep -r "\.md" src/Services --include="*.php" | grep -i prompt
```

Expected: No results (the PromptsService that loaded them is deleted)

**Step 3: Delete the prompts directory**

Run:
```bash
rm -rf resources/prompts/
```

**Step 4: Verify resources directory structure**

Run:
```bash
ls -la resources/
```

Expected: Should still have `actions/`, `js/`, etc. but not `prompts/`

**Step 5: Commit**

```bash
git rm -r resources/prompts/
git commit -m "remove: delete orphaned markdown prompt files (replaced by MagicAction classes)"
```

---

## Task 5: Create ActionRegistry service

**Files:**
- Create: `src/Services/ActionRegistry.php`
- Modify: `src/ServiceProvider.php` (register ActionRegistry, use it in bootAddon)
- Test: `tests/Unit/Services/ActionRegistryTest.php` (basic test)

**Step 1: Write ActionRegistry class**

Create `src/Services/ActionRegistry.php`:

```php
<?php

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use ReflectionClass;

/**
 * Registry for managing Magic Action metadata and discovery.
 *
 * Centralizes action handle resolution and class discovery to prevent
 * duplication across ServiceProvider, FieldConfigService, and ActionLoader.
 */
final class ActionRegistry
{
    /**
     * @var array<string, class-string<BaseMagicAction>>
     */
    private array $handles = [];

    /**
     * @var array<string, BaseMagicAction>
     */
    private array $instances = [];

    /**
     * Discover and register all MagicAction classes from a given namespace.
     *
     * @param string $namespace The namespace to scan (e.g., 'ElSchneider\\StatamicMagicActions\\MagicActions')
     */
    public function discoverFromNamespace(string $namespace): void
    {
        $baseDir = base_path('vendor/el-schneider/statamic-magic-actions/src/MagicActions');
        $files = glob($baseDir . '/*.php');

        foreach ($files ?? [] as $file) {
            if ($file === dirname($file) . '/BaseMagicAction.php') {
                continue;
            }

            $className = basename($file, '.php');
            $fqcn = $namespace . '\\' . $className;

            if (class_exists($fqcn) && is_subclass_of($fqcn, BaseMagicAction::class)) {
                try {
                    $instance = new $fqcn();
                    $this->handles[$instance->getHandle()] = $fqcn;
                } catch (\Throwable $e) {
                    // Skip actions that fail to instantiate
                    continue;
                }
            }
        }
    }

    /**
     * Get the class path for an action by handle.
     */
    public function getClassPath(string $handle): ?string
    {
        return $this->handles[$handle] ?? null;
    }

    /**
     * Get an action instance by handle (cached).
     */
    public function getInstance(string $handle): ?BaseMagicAction
    {
        if (!isset($this->handles[$handle])) {
            return null;
        }

        if (!isset($this->instances[$handle])) {
            $class = $this->handles[$handle];
            $this->instances[$handle] = new $class();
        }

        return $this->instances[$handle];
    }

    /**
     * Get all registered action handles.
     *
     * @return array<string>
     */
    public function getAllHandles(): array
    {
        return array_keys($this->handles);
    }

    /**
     * Get all registered actions as [handle => instance] pairs.
     *
     * @return array<string, BaseMagicAction>
     */
    public function getAllInstances(): array
    {
        foreach ($this->handles as $handle => $class) {
            if (!isset($this->instances[$handle])) {
                $this->instances[$handle] = new $class();
            }
        }

        return $this->instances;
    }
}
```

**Step 2: Register ActionRegistry in ServiceProvider**

Edit `src/ServiceProvider.php` at the end of `register()` method (around line 35):

Add after existing registrations:

```php
$this->app->singleton(ActionRegistry::class, function () {
    $registry = new ActionRegistry();
    $registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');
    return $registry;
});
```

**Step 3: Write a basic test for ActionRegistry**

Create `tests/Unit/Services/ActionRegistryTest.php`:

```php
<?php

namespace ElSchneider\StatamicMagicActions\Tests\Unit\Services;

use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use PHPUnit\Framework\TestCase;

class ActionRegistryTest extends TestCase
{
    private ActionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ActionRegistry();
        $this->registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');
    }

    public function test_discovers_magic_actions(): void
    {
        $handles = $this->registry->getAllHandles();
        $this->assertNotEmpty($handles);
        $this->assertContains('propose-title', $handles);
    }

    public function test_gets_instance_by_handle(): void
    {
        $instance = $this->registry->getInstance('propose-title');
        $this->assertInstanceOf(ProposeTitle::class, $instance);
    }

    public function test_caches_instances(): void
    {
        $instance1 = $this->registry->getInstance('propose-title');
        $instance2 = $this->registry->getInstance('propose-title');
        $this->assertSame($instance1, $instance2);
    }

    public function test_returns_null_for_unknown_handle(): void
    {
        $instance = $this->registry->getInstance('nonexistent-action');
        $this->assertNull($instance);
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/pest tests/Unit/Services/ActionRegistryTest.php -v
```

Expected: All tests pass.

**Step 5: Commit**

```bash
git add src/Services/ActionRegistry.php tests/Unit/Services/ActionRegistryTest.php src/ServiceProvider.php
git commit -m "feat: add ActionRegistry service for centralized action discovery"
```

---

## Task 6: Refactor ServiceProvider::bootAddon() into focused methods

**Files:**
- Modify: `src/ServiceProvider.php:50-133` (break into private methods)

**Step 1: Add private method to extract blueprint from request**

Edit `src/ServiceProvider.php`, add after `bootAddon()` method:

```php
/**
 * Extract the current blueprint from the request path.
 *
 * Handles two URL patterns:
 * - /cp/collections/entries/posts/123 (entry editing)
 * - /cp/assets/images (asset management)
 *
 * @return \Statamic\CP\Contracts\Blueprint|null
 */
private function extractBlueprintFromRequest(): ?\Statamic\CP\Contracts\Blueprint
{
    if (!$this->isStatamicControlPanel()) {
        return null;
    }

    $requestPath = request()->path();

    // Pattern: /cp/collections/entries/{collection}/{id}
    if (preg_match('/entries\/([^\/]+)$/', $requestPath, $matches)) {
        $collection = $matches[1];
        try {
            $entry = Entry::query()->first();
            if ($entry) {
                return $entry->blueprint();
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Pattern: /cp/assets/{container}
    if (preg_match('/assets\/([^\/]+)$/', $requestPath, $matches)) {
        $container = $matches[1];
        try {
            $asset = Asset::query()->first();
            if ($asset) {
                return $asset->blueprint();
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    return null;
}

/**
 * Check if we're in the Statamic control panel.
 */
private function isStatamicControlPanel(): bool
{
    return request()->path() !== null && str_starts_with(request()->path(), 'cp/');
}

/**
 * Build magic field configuration for a given blueprint.
 */
private function buildMagicFieldsConfig(
    ?\Statamic\CP\Contracts\Blueprint $blueprint,
    ActionRegistry $actionRegistry
): array
{
    if (!$blueprint) {
        return [];
    }

    $magicFieldsConfig = [];

    foreach ($blueprint->fields()->all() as $field) {
        if (!($field->config()['magic_actions_enabled'] ?? false)) {
            continue;
        }

        $fieldtype = $field->fieldtype()->handle();
        $fieldActions = [];

        foreach (config('statamic.magic-actions.fieldtypes')[$fieldtype]['actions'] ?? [] as $action) {
            $actionData = null;

            if (is_string($action)) {
                // FQCN format - get handle from instance
                if (class_exists($action) && is_subclass_of($action, BaseMagicAction::class)) {
                    $instance = new $action();
                    $actionData = [
                        'action' => $instance->getHandle(),
                        'title' => $instance->getTitle(),
                        'type' => $instance->getType(),
                    ];
                }
            } elseif (is_array($action) && isset($action['action'])) {
                // Pre-formatted array from config
                $actionData = $action;
            }

            if ($actionData) {
                $fieldActions[] = $actionData;
            }
        }

        if (!empty($fieldActions)) {
            $magicFieldsConfig[$field->handle()] = $fieldActions;
        }
    }

    return $magicFieldsConfig;
}
```

**Step 2: Simplify bootAddon() method**

Replace the entire `bootAddon()` method (lines 50-133) with:

```php
public function bootAddon(): void
{
    $actionRegistry = $this->app->make(ActionRegistry::class);
    $blueprint = $this->extractBlueprintFromRequest();
    $magicFieldsConfig = $this->buildMagicFieldsConfig($blueprint, $actionRegistry);

    $this->publishes([
        __DIR__.'/../config/statamic/magic-actions.php' => config_path('statamic/magic-actions.php'),
    ], 'statamic-magic-actions-config');

    // Publish config if not exists
    if (!file_exists(config_path('statamic/magic-actions.php'))) {
        $this->publish([
            __DIR__.'/../config/statamic/magic-actions.php' => config_path('statamic/magic-actions.php'),
        ]);
    }

    // Pass magic fields config to frontend
    Statamic::script('addon-magic-actions', $this->getScriptPath());
    Statamic::provideToScript('magicFieldsConfig', $magicFieldsConfig);
}

/**
 * Get the path to the compiled addon script.
 */
private function getScriptPath(): string
{
    return $this->basePath('resources/js/addon.js');
}
```

**Step 3: Run tests to verify no breakage**

```bash
./vendor/bin/pest tests/ -v
```

Expected: All tests pass (or same failures as before if there were any).

**Step 4: Run the addon locally to verify it still works**

- Load the control panel at `http://statamic-magic-actions-test.test/cp`
- Navigate to an entry with magic actions enabled
- Verify magic action buttons appear

**Step 5: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "refactor: extract ServiceProvider bootAddon() into focused private methods

Improves readability by breaking 84-line method into:
- extractBlueprintFromRequest(): handles URL parsing
- buildMagicFieldsConfig(): builds config for frontend
- isStatamicControlPanel(): simple guard
- getScriptPath(): simple utility

No functional changes."
```

---

## Task 7: Update FieldConfigService to use ActionRegistry

**Files:**
- Modify: `src/Services/FieldConfigService.php` (use ActionRegistry instead of inline resolution)

**Step 1: Update FieldConfigService to use ActionRegistry**

Edit `src/Services/FieldConfigService.php`, modify the `getFieldtypesWithPrompts()` method:

Current implementation around lines 82-104 likely has inline action resolution. Replace with:

```php
public function getFieldtypesWithPrompts(ActionRegistry $actionRegistry): array
{
    $fieldtypesConfig = config('statamic.magic-actions.fieldtypes', []);

    return collect($fieldtypesConfig)
        ->map(function (array $fieldtype) use ($actionRegistry) {
            return [
                'actions' => collect($fieldtype['actions'] ?? [])
                    ->map(function ($action) use ($actionRegistry) {
                        if (is_string($action)) {
                            // FQCN - get instance from registry
                            $parts = explode('\\', $action);
                            $className = end($parts);
                            $handle = $this->classNameToHandle($className);

                            $instance = $actionRegistry->getInstance($handle);
                            if ($instance) {
                                return [
                                    'action' => $instance->getHandle(),
                                    'title' => $instance->getTitle(),
                                    'type' => $instance->getType(),
                                ];
                            }
                        } elseif (is_array($action)) {
                            return $action;
                        }

                        return null;
                    })
                    ->filter()
                    ->values()
                    ->toArray(),
            ];
        })
        ->toArray();
}

/**
 * Convert class name to handle (kebab-case).
 */
private function classNameToHandle(string $className): string
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $className));
}
```

**Step 2: Update constructor to accept ActionRegistry**

At the top of `FieldConfigService.php`, update the constructor:

```php
public function __construct(
    private ActionRegistry $actionRegistry,
) {}
```

**Step 3: Update method calls to use ActionRegistry**

Update any calls to `getFieldtypesWithPrompts()` to pass ActionRegistry:

```php
$this->getFieldtypesWithPrompts($this->actionRegistry)
```

**Step 4: Run tests**

```bash
./vendor/bin/pest tests/ -v
```

Expected: All tests pass.

**Step 5: Commit**

```bash
git add src/Services/FieldConfigService.php
git commit -m "refactor: use ActionRegistry in FieldConfigService

Eliminates duplicate action discovery logic. FieldConfigService
now delegates to ActionRegistry for consistent action metadata."
```

---

## Task 8: Consolidate class-to-handle conversion logic

**Files:**
- Modify: `src/Services/ActionLoader.php` (remove duplicate conversion method)
- Modify: `src/MagicActions/BaseMagicAction.php` (verify it uses centralized logic)

**Step 1: Add public utility method to ActionRegistry**

Edit `src/Services/ActionRegistry.php`, add this method:

```php
/**
 * Convert a class name to a handle (kebab-case).
 *
 * Example: ProposeTitle -> propose-title
 */
public static function classNameToHandle(string $className): string
{
    // Remove any namespace
    $className = basename(str_replace('\\', '/', $className));

    // Convert PascalCase to kebab-case
    $kebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $className));

    return $kebab;
}

/**
 * Convert a handle to a class name (PascalCase).
 *
 * Example: propose-title -> ProposeTitle
 */
public static function handleToClassName(string $handle): string
{
    return str_replace(
        ' ',
        '',
        ucwords(str_replace('-', ' ', $handle))
    );
}
```

**Step 2: Update ActionLoader to use ActionRegistry methods**

Edit `src/Services/ActionLoader.php`, find the method that converts handles to class names and replace it:

```php
// OLD CODE - remove
private function convertActionNameToClassName(string $actionName): string
{
    $explodedHandle = str_replace('-', ' ', $actionName);
    return str_replace(' ', '', ucwords($explodedHandle));
}

// NEW CODE - use static method
private function convertActionNameToClassName(string $actionName): string
{
    return ActionRegistry::handleToClassName($actionName);
}
```

**Step 3: Update BaseMagicAction to use ActionRegistry**

Edit `src/MagicActions/BaseMagicAction.php`, find `deriveHandle()` method:

```php
// OLD CODE - remove
private function deriveHandle(): string
{
    $className = class_basename($this);
    $exploded = str_replace('-', ' ', $className);
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $className));
}

// NEW CODE - use static method
private function deriveHandle(): string
{
    return ActionRegistry::classNameToHandle(static::class);
}
```

**Step 4: Run tests**

```bash
./vendor/bin/pest tests/ -v
```

Expected: All tests pass.

**Step 5: Verify no duplicate conversion methods remain**

```bash
grep -n "convertActionNameToClassName\|classNameToHandle\|handleToClassName\|deriveHandle" src/**/*.php
```

Expected: Should see references to ActionRegistry static methods and minimal local usage.

**Step 6: Commit**

```bash
git add src/Services/ActionRegistry.php src/Services/ActionLoader.php src/MagicActions/BaseMagicAction.php
git commit -m "refactor: consolidate class-to-handle conversion into ActionRegistry

All PascalCase <-> kebab-case conversions now use ActionRegistry
static methods. Eliminates three duplicate implementations."
```

---

## Task 9: Final cleanup and verification

**Files:**
- Verify all tests pass
- Verify application works end-to-end

**Step 1: Run full test suite**

```bash
./vendor/bin/pest tests/ -v
```

Expected: All tests pass (or same failures as before).

**Step 2: Run code quality checks**

```bash
pint check
```

If fixes needed:
```bash
pint fix
```

**Step 3: Test the addon in the control panel**

- Open `http://statamic-magic-actions-test.test/cp`
- Login with `claude@claude.ai` / `claude`
- Navigate to an entry that has magic-actions enabled
- Verify magic action buttons appear and function correctly

**Step 4: Check for any remaining dead code**

```bash
grep -r "PromptsService\|AssetsService\|OpenAIApiException" src tests resources --include="*.php" --include="*.ts" --include="*.vue"
```

Expected: No results.

**Step 5: Final commit with summary**

```bash
git log --oneline -10
```

Should see:
- remove: delete unused PromptsService
- remove: delete unused AssetsService
- remove: delete unused OpenAIApiException
- remove: delete orphaned markdown prompt files
- feat: add ActionRegistry service
- refactor: extract ServiceProvider bootAddon()
- refactor: use ActionRegistry in FieldConfigService
- refactor: consolidate class-to-handle conversion

**Step 6: Create a summary commit message**

```bash
git log --format="%h %s" main..HEAD
```

Copy the commit hashes and create a summary (if desired) or leave as is.

---

## Success Criteria

✅ All dead code removed (PromptsService, AssetsService, OpenAIApiException, markdown prompts)
✅ ServiceProvider bootAddon() refactored into focused methods (from 84 lines to ~15 lines)
✅ ActionRegistry service created and tested
✅ All action discovery consolidated into ActionRegistry
✅ All tests pass
✅ No broken imports or references
✅ Addon still works in the control panel
✅ Code quality checks pass (pint)

---

Plan complete and saved. Ready to execute with subagent-driven development.
