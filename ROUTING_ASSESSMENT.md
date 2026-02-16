# Routing Architecture Assessment: Action Routes vs CP Routes

## Executive recommendation

Move the AI endpoints (`completion`, `vision`, `transcribe`, `status`, `batch status`) to **CP routes** (`routes/cp.php`) and treat the current action-route URLs as legacy compatibility routes for one transition release.

This addon feature is CP-only in practice (field actions / bulk actions inside the control panel), and CP routes are the native place for CP-authenticated JSON endpoints in Statamic. Keeping them as action routes works, but it is structurally weaker and easier to misconfigure.

## 1. How `AddonServiceProvider` handles action vs CP routes

Statamic auto-discovers addon route files in `bootRoutes()`:

- `routes/cp.php` -> `registerCpRoutes(...)`
- `routes/actions.php` -> `registerActionRoutes(...)`
- Source: `vendor/statamic/cms/src/Providers/AddonServiceProvider.php:531`.

Key difference in registration:

- CP routes:
  - `registerCpRoutes()` only groups by namespace and pushes into CP route bucket.
  - No addon slug prefix is added automatically.
  - Source: `vendor/statamic/cms/src/Providers/AddonServiceProvider.php:592`.
- Action routes:
  - `registerActionRoutes()` groups by namespace **and auto-prefixes addon slug**.
  - Source: `vendor/statamic/cms/src/Providers/AddonServiceProvider.php:605`.

So for this addon (`statamic-magic-actions` slug), action routes get `/statamic-magic-actions/...` appended automatically; CP routes only get what you define in `routes/cp.php`.

## 2. Middleware each route type gets automatically

## CP routes (automatic stack)

CP route mounting chain:

1. `routes/routes.php` mounts CP routes under:
   - middleware: `SwapCpExceptionHandler` + `statamic.cp`
   - prefix: `config('statamic.cp.route')`
   - name prefix: `statamic.cp.`
   - Source: `vendor/statamic/cms/routes/routes.php:21`.
2. Inside `routes/cp.php`, `Statamic::additionalCpRoutes()` is called inside `Route::middleware('statamic.cp.authenticated')`.
   - Source: `vendor/statamic/cms/routes/cp.php:129`.

So addon CP routes get both middleware groups:

- `statamic.cp` (cookies/session/CSRF/substitute bindings/CP auth guard/etc.)
  - Source group definition: `vendor/statamic/cms/src/Providers/CpServiceProvider.php:82`.
- `statamic.cp.authenticated` (authenticate session, authorize CP access, localize, permission/preferences boot, etc.)
  - Source group definition: `vendor/statamic/cms/src/Providers/CpServiceProvider.php:95`.

Important: `statamic.cp` includes `Statamic\Http\Middleware\CP\AuthGuard`, which switches to the configured CP guard.

- Source: `vendor/statamic/cms/src/Providers/CpServiceProvider.php:90`.

## Action routes (automatic stack)

Action route mounting chain:

1. `routes/routes.php` loads `routes/web.php` with middleware `config('statamic.routes.middleware', 'web')`.
   - Source: `vendor/statamic/cms/routes/routes.php:34`.
2. `routes/web.php` defines action prefix `config('statamic.routes.action')` and runs `Statamic::additionalActionRoutes()` there.
   - Source: `vendor/statamic/cms/routes/web.php:26` and `vendor/statamic/cms/routes/web.php:52`.

So addon action routes get:

- Whatever is in `statamic.routes.middleware` (default: Laravel `web` middleware).
- They **do not** automatically get `statamic.cp` or CP exception handler wrapping.

In this addon, you manually added `statamic.cp.authenticated` in `routes/actions.php`.

- Source: `routes/actions.php:8`.

That protects endpoints, but it is still not identical to true CP routes because `statamic.cp` (including CP `AuthGuard`) is not part of the stack by default.

## 3. URL structure each route type produces

## Current action-route URLs (today)

From current `routes/actions.php` and auto-prefixing:

- `POST /!/statamic-magic-actions/completion`
- `POST /!/statamic-magic-actions/vision`
- `POST /!/statamic-magic-actions/transcribe`
- `GET /!/statamic-magic-actions/status/{jobId}`
- `GET /!/statamic-magic-actions/batch/{batchId}/status`

Why:

- `!` = `config('statamic.routes.action')` default
  - Source: `vendor/statamic/cms/config/routes.php:43`.
- `statamic-magic-actions` = addon slug auto-prefixed for action routes
  - Source: `vendor/statamic/cms/src/Providers/AddonServiceProvider.php:609`.

## CP route URL pattern

CP routes live under:

- `/{cp.route}/...` where `cp.route` defaults to `cp`
  - Source: `vendor/statamic/cms/config/cp.php:16`.

Your current CP settings routes are:

- `GET /cp/magic-actions/settings`
- `POST /cp/magic-actions/settings`
- Source: `routes/cp.php:8`.

If AI endpoints move into `routes/cp.php` under same prefix, expected URLs become e.g.:

- `POST /cp/magic-actions/completion`
- etc. (or equivalent under custom CP route, e.g. `/admin/...` if CP route is customized).

## 4. How frontend JS currently calls these endpoints

Frontend API calls are hardcoded in `resources/js/api.ts`:

- `completion: '/!/statamic-magic-actions/completion'`
- `vision: '/!/statamic-magic-actions/vision'`
- `transcription: '/!/statamic-magic-actions/transcribe'`
- `status: '/!/statamic-magic-actions/status'`
- Source: `resources/js/api.ts:4`.

All calls go through `window.Statamic.$axios`.

- Source: `resources/js/api.ts:38`, `resources/js/api.ts:61`, `resources/js/api.ts:81`, `resources/js/api.ts:99`.

Built asset also contains the same hardcoded paths.

- Source: `resources/dist/build/assets/addon-DWe-30P5.js` (compiled constant object).

## 5. Would moving to CP routes break frontend?

Yes, immediately, unless JS URLs are changed.

If backend routes move from `/!/statamic-magic-actions/*` to `/cp/magic-actions/*` and JS stays unchanged, requests will 404.

Required frontend changes:

1. Update endpoint base away from action-route path.
2. Do not hardcode `/cp`; use CP-config-aware value (`window.StatamicConfig.cpRoot`) or server-provided route URLs.
   - `cpRoot` is already exposed by Statamic JS bootstrap.
   - Source: `vendor/statamic/cms/src/Http/View/Composers/JavascriptComposer.php:37`.

Best approach:

- Provide concrete endpoint URLs from PHP (`Statamic::provideToScript`) and consume them in JS.
- That avoids hardcoding both action prefix (`!`) and CP prefix (`cp`), and survives custom route prefixes.

## 6. Other trade-offs and architectural notes

## Security and guard consistency

- Current action-route approach (`web` + manual `statamic.cp.authenticated`) may work, but skips `statamic.cp` middleware unless explicitly added.
- `statamic.cp` includes CP `AuthGuard` (`Auth::shouldUse(config('statamic.users.guards.cp'))`).
  - Source: `vendor/statamic/cms/src/Http/Middleware/CP/AuthGuard.php:12`.
- Without that, auth guard behavior can diverge if CP guard differs from default web guard.

CP routes avoid this class of mismatch by construction.

## CSRF

- Action routes: protected by default `web` middleware stack (unless project customized `statamic.routes.middleware`).
- CP routes: protected by `statamic.cp` group including `VerifyCsrfToken`.

So CSRF is available in both, but CP routes are more explicit and stable for CP-only behavior.

## Prefixing and conventions

- Action routes are conventionally frontend action endpoints (`/!/...`).
- CP routes are conventionally control-panel endpoints (`/cp/...`), including JSON endpoints.

These AI endpoints are CP workflow endpoints, not frontend/site actions, so CP routing fits the product boundary better.

## Naming/collision ergonomics

- Action routes in this addon use generic names (`completion`, `status`, etc.) in `routes/actions.php`.
- Because action routes are in Statamic's global `statamic.` name group, generic route names are easier to collide with across addons.
- CP routes typically live under `statamic.cp.` plus addon-specific naming/prefix and are cleaner to namespace.

## Final recommendation

Use **CP routes** for these AI endpoints.

Implementation strategy I recommend:

1. Add AI endpoints to `routes/cp.php` under `prefix('magic-actions')` with explicit names (e.g. `magic-actions.ai.completion`, etc.).
2. Update `resources/js/api.ts` to consume CP URLs from `window.StatamicConfig` (or a `magicActionEndpoints` object injected from PHP).
3. Keep existing action routes temporarily as compatibility aliases (same controller methods) for one release cycle.
4. Mark action URLs deprecated in docs/changelog, then remove in next breaking release.

Given the addon is `v0.1.0` and public API is semi-stable, this migration should be called out clearly in `CHANGELOG.md` if/when aliases are removed.
