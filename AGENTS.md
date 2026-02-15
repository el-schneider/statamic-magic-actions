# AGENTS.md

This file provides guidance to AI coding agents working with this repository.

> **Important**: This addon is currently unreleased. Never worry about breaking changes or backwards compatibility.

## Project Overview

**Statamic Magic Actions** is a Statamic CMS addon that integrates AI-powered actions into the Statamic control panel. Content editors can trigger magic actions (powered by OpenAI or Anthropic via [Prism PHP](https://prismphp.dev/)) directly on field types to generate or transform content.

Three types of AI operations:
- **Completion**: Text-to-text (titles, meta descriptions, teasers)
- **Vision**: Image analysis (alt text, captions, image tags)
- **Transcription**: Audio-to-text (audio file transcription)

## Architecture

### Backend (PHP/Laravel)

| Component | Purpose |
|---|---|
| `src/ServiceProvider.php` | Addon bootstrap, registers services, delivers global action catalog |
| `src/Http/Controllers/ActionsController.php` | API endpoints for starting and monitoring jobs |
| `src/MagicActions/` | Action definitions — **single source of truth** for all action metadata |
| `src/Actions/DynamicBulkAction.php` | Single adapter class for all Statamic bulk actions |
| `src/Services/ActionExecutor.php` | Unified execution service for HTTP, CLI, and bulk entry points |
| `src/Services/ActionRegistry.php` | Discovers and registers MagicAction classes |
| `src/Services/BulkActionRegistrar.php` | Auto-registers bulk actions from MagicActions with `supportsBulk()` |
| `src/Services/MagicFieldsConfigBuilder.php` | Builds the global action catalog for the frontend |
| `src/Services/ContextResolver.php` | Resolves runtime context (taxonomy terms, entry content, etc.) |
| `src/Services/JobTracker.php` | Job and batch tracking |
| `src/Commands/MagicRunCommand.php` | CLI `magic:run` command |
| `config/statamic/magic-actions.php` | Provider credentials, action definitions, fieldtype mappings |

### Frontend (TypeScript)

| File | Purpose |
|---|---|
| `resources/js/addon.ts` | Main entry point — registers field actions from global catalog |
| `resources/js/api.ts` | API client for completion, vision, transcription endpoints |
| `resources/js/helpers.ts` | URL parsing, page context extraction, MIME type checks |
| `resources/js/job-tracker.ts` | Background job tracking with localStorage persistence |
| `resources/js/types.ts` | TypeScript type definitions |

### Key Design Decisions

- **MagicActions are the single source of truth**: All action metadata (title, type, bulk support, confirmation text) lives in `src/MagicActions/`. Bulk actions, field actions, and CLI all derive from this.
- **Global action catalog**: Frontend receives a global catalog of all actions grouped by fieldtype component. Field visibility is determined by each field's own `config` at render time (not injected per-blueprint).
- **DynamicBulkAction**: One class handles ALL Statamic bulk actions. Adding bulk support = return `true` from `supportsBulk()` on any MagicAction.
- **No per-blueprint script injection**: Removed event listeners and view composers. The catalog is truly global and works with SPA navigation.

### Data Flow

1. Editor clicks magic action button → field action `run()` callback fires
2. `extractPageContext()` determines entry/asset context from current URL
3. API call to `ActionsController` → validates, dispatches async job
4. Frontend polls `/actions/status/{jobId}` for completion
5. Result returned, field updated via `update()` callback

## Development

### Sandbox

Development happens in [statamic-magic-actions-sandbox](https://github.com/soft-lifes/statamic-magic-actions-sandbox). The addon is a git submodule at `addons/statamic-magic-actions/`.

```bash
# Sandbox CP
http://localhost:8103/cp
# Credentials: test@sandbox.test / sandbox123
```

### Code Quality

```bash
prettier --check .
prettier --write .
./vendor/bin/pint --test
./vendor/bin/pint
```

### Testing

```bash
./vendor/bin/pest
./vendor/bin/pest --filter=SomeTest
```

> Tests are not yet up to date — being addressed in Phase 5.

### Building Frontend

```bash
npm run build
```

After building, publish assets in the sandbox:
```bash
php artisan vendor:publish --tag=statamic-magic-actions --force
```

## Critical: Always Verify Frontend Changes

After ANY change that touches frontend code (TypeScript, Vite build, asset publishing):

1. Run `npm run build` and confirm `resources/dist/build/manifest.json` points to the new file
2. Publish assets and clean old files from `public/vendor/statamic-magic-actions/build/assets/`
3. **Verify via browser** (Playwright, agent-browser, or equivalent) that the change actually works
4. Check browser console for errors
5. Never assume a build succeeded just because it didn't error — always confirm the output is served correctly

## PHP Standards

- `declare(strict_types=1)` everywhere
- `final class` for classes not designed for inheritance
- Explicit return type declarations
- PSR-12 naming conventions
- Prefer early returns over deep nesting
- No nested ternary operators
