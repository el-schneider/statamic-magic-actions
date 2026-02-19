# AGENTS.md

Statamic addon: AI-powered actions on CP fields via [Prism PHP](https://prismphp.dev/). Released as v0.1.0.

## Architecture — The One Rule

**`src/MagicActions/` is the single source of truth.** Everything derives from MagicAction classes:

- Field actions (frontend JS reads the action catalog)
- Bulk actions (`DynamicBulkAction` adapter, auto-registered by `BulkActionRegistrar`)
- CLI (`MagicRunCommand`)
- Settings blueprint (action-specific config)

To add a new action: create a class in `src/MagicActions/` extending `BaseMagicAction`. Everything else follows automatically.

## Key Patterns

| Pattern                | Implementation                                                                                |
| ---------------------- | --------------------------------------------------------------------------------------------- |
| Single source of truth | `src/MagicActions/*.php` — all metadata lives here                                            |
| One bulk action class  | `src/Actions/DynamicBulkAction.php` — adapts any MagicAction                                  |
| Global action catalog  | `MagicFieldsConfigBuilder` builds once, frontend checks field config at render time           |
| Lazy page context      | `extractPageContext()` runs at execution time, not registration — critical for SPA navigation |
| Multi-provider         | `ProviderConfig` + Prism PHP — supports OpenAI, Anthropic, Gemini, Mistral                    |
| Async processing       | `ProcessPromptJob` via Laravel queue — field actions dispatch jobs, frontend polls status     |
| Context resolution     | `ContextResolver` extracts entry/asset content for actions that implement `RequiresContext`   |

## File Map

```
src/
├── MagicActions/          # Action classes (THE source of truth)
│   ├── BaseMagicAction.php  # Abstract base — extend this
│   ├── AltText.php, ProposeTitle.php, CreateTeaser.php, ...
├── Services/
│   ├── ActionRegistry.php       # Discovers + registers actions
│   ├── ActionExecutor.php       # Runs actions via Prism
│   ├── ActionLoader.php         # Loads action config from settings
│   ├── BulkActionRegistrar.php  # Auto-registers bulk actions
│   ├── ContextResolver.php      # Extracts entry/asset content
│   ├── FieldConfigService.php   # Per-field action visibility
│   ├── MagicFieldsConfigBuilder.php  # Global JS catalog
│   ├── ProviderConfig.php       # Multi-provider setup
│   └── JobTracker.php           # Async job status
├── Actions/
│   └── DynamicBulkAction.php    # Universal bulk action adapter
├── Http/Controllers/
│   ├── ActionsController.php    # AI endpoints (completion/vision/transcribe)
│   └── CP/SettingsController.php  # Settings page (v5 Blade / v6 Inertia)
├── Contracts/
│   ├── MagicAction.php          # Interface
│   └── RequiresContext.php      # Actions needing entry/asset content
├── Settings.php                 # Settings reader (YAML flat-file)
├── Settings/Blueprint.php       # Settings blueprint builder
├── Commands/MagicRunCommand.php # CLI: php artisan magic:run
└── ServiceProvider.php          # Boot + registration
resources/js/
└── addon.ts                     # Frontend (vanilla TS, Vite)
```

## Gotchas

- **Vite manifest**: After `npm run build`, ALWAYS verify `resources/dist/manifest.json` points to the new file. Stale manifests serve old JS silently.
- **SPA navigation**: Field actions must resolve page context lazily (inside `run()` callback), not at registration time. The URL changes without full page reload in Statamic's CP.
- **Statamic 6**: Uses Inertia instead of Blade+Vue. `<publish-form>` Vue component does NOT exist in v6. Settings page uses `Statamic\CP\PublishForm::make()` (PHP/Inertia). The controller branches on `class_exists(\Statamic\CP\PublishForm::class)`.
- **Settings storage**: Flat YAML at `content/magic-actions/settings.yaml`, NOT Laravel config or database.
- **Route names**: All prefixed `statamic.cp.` in full (e.g. `statamic.cp.magic-actions.settings.index`). The `cp.php` routes file only specifies `magic-actions.*` — Statamic adds the prefix.
- **Auth middleware**: All CP routes are behind Statamic's auth middleware. Action routes (`ActionsController`) also require auth.

## Development

```bash
npm run check      # prettier + eslint + pint
npm run fix         # auto-fix all
composer run ci     # pint + pest
npm run build       # Vite build (then verify manifest!)
```

Sandbox: [soft-lifes/statamic-magic-actions-sandbox](https://github.com/soft-lifes/statamic-magic-actions-sandbox) — addon mounted as submodule at `addons/statamic-magic-actions/`.

## Compatibility

- Statamic 5 (`^5.0`): Blade views, `<publish-form>` Vue component for settings
- Statamic 6 (`^6.0`): Inertia, `PublishForm::make()` for settings — work in progress on `feat/statamic-6-compat` branch
