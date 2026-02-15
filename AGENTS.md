# AGENTS.md

> This addon is **unreleased**. Don't worry about breaking changes.

## What This Is

Statamic addon: AI-powered actions on CP fields (generate titles, alt text, captions, etc.) via [Prism PHP](https://prismphp.dev/).

## Key Architecture Principle

**MagicActions (`src/MagicActions/`) are the single source of truth.** All metadata lives there. Bulk actions, field actions, CLI — all derive from MagicAction classes. To add a new action, create a class there and everything else follows.

## Development

Sandbox: [soft-lifes/statamic-magic-actions-sandbox](https://github.com/soft-lifes/statamic-magic-actions-sandbox) — addon is a submodule at `addons/statamic-magic-actions/`.

```bash
npm run check      # prettier + eslint + pint
npm run fix         # auto-fix all
composer run ci     # pint + pest
```

## Frontend Changes

After touching TypeScript/Vite: build → publish → **verify in browser**. Don't assume builds worked.

```bash
npm run build
# In sandbox:
php artisan vendor:publish --tag=statamic-magic-actions --force
```
