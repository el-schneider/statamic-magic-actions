# Proposal: Refactor Magic Field Config Delivery for SPA/Inertia Compatibility

## Summary

The current approach (`Statamic::provideToScript(['magicFields' => ...])` from blueprint listeners + a view composer) is inherently request-time and page-render-time. In Statamic v5 this is already fragile because parts of the CP update via XHR/history APIs without a full page render. In Statamic v6 (full Inertia CP), this mismatch becomes more pronounced.

Recommended direction: **stop delivering per-blueprint `magicFields` via global script injection**. Instead, make frontend visibility/action selection derive from each field’s own `config` payload (already part of Statamic publish data), and deliver only a **global action catalog** (fieldtype component -> available action metadata). This removes page-context drift and works cleanly with both v5 and v6 navigation models.

## Research Findings

### 1) How `provideToScript` works (v5 core)

- `Statamic::provideToScript()` merges values into static `Statamic::$jsonVariables`.
- Those variables are emitted into `window.StatamicConfig` in `statamic::partials.scripts`.
- This script block is rendered when the Blade layout renders the page, not on subsequent in-page state transitions.

Relevant files:

- `vendor/statamic/cms/src/Statamic.php`
- `vendor/statamic/cms/resources/views/partials/scripts.blade.php`

### 2) Why it is stale in real CP flows

Core CP behavior already includes in-page transitions and XHR refreshes:

- Entry publish form localization switch fetches JSON (`this.$axios.get(localization.url)`) and replaces `values/meta/blueprint` client-side.
- Asset browser uses `history.pushState` and internal navigation without full page reload.
- Asset editor loads current asset data (including blueprint/meta/values) via XHR.

Relevant files:

- `vendor/statamic/cms/resources/js/components/entries/PublishForm.vue`
- `vendor/statamic/cms/resources/js/components/assets/AssetManager.vue`
- `vendor/statamic/cms/resources/js/components/assets/Browser/Browser.vue`
- `vendor/statamic/cms/resources/js/components/assets/Editor/Editor.vue`

### 3) Addon-specific fragility in current implementation

Current addon code:

- Reads `window.StatamicConfig.magicFields` once and registers actions on DOM ready.
- Populates `magicFields` through `EntryBlueprintFound` / `AssetContainerBlueprintFound` listeners.
- Uses broad route-name prefix checks (`statamic.cp.collections*`, `statamic.cp.assets*`).

Important discovery:

- `EntryBlueprintFound` is dispatched from multiple paths (`Entry::blueprint`, `Collection::entryBlueprint`, GraphQL type setup), not only the edit screen.

Relevant files:

- `resources/js/addon.ts`
- `src/Listeners/ProvideEntryMagicActionsToScript.php`
- `src/Listeners/ProvideAssetMagicActionsToScript.php`
- `vendor/statamic/cms/src/Entries/Entry.php`
- `vendor/statamic/cms/src/Entries/Collection.php`
- `vendor/statamic/cms/src/GraphQL/Types/EntryInterface.php`

### 4) Core patterns for dynamic CP data

Statamic core typically delivers dynamic publish data through controller responses/JSON and component props, not through repeated global-script mutation.

Examples:

- Entry edit controller returns full publish payload and JSON when `wantsJson`.
- Asset endpoints return structured JSON resource payloads used by Vue components.
- CP “API-style” routes live under CP middleware and controllers extending `CpController`.

Relevant files:

- `vendor/statamic/cms/src/Http/Controllers/CP/Collections/EntriesController.php`
- `vendor/statamic/cms/src/Http/Controllers/CP/Assets/AssetsController.php`
- `vendor/statamic/cms/src/Http/Resources/CP/Assets/Asset.php`
- `vendor/statamic/cms/routes/cp.php`

### 5) v6 compatibility findings

From Statamic 6 source/docs:

- CP is Inertia-based (middleware: `HandleInertiaRequests`, `HandleAuthenticatedInertiaRequests`).
- `provideToScript` and `StatamicConfig` still exist, but they are still global bootstrap-style data; they are not a per-page reactive transport.
- Official guidance strongly recommends Inertia-based CP pages/components for SPA behavior.
- Upgrade docs call out limitations of Blade-based CP pages.

Primary sources:

- Statamic 6 source (`6.x` branch):
  - `src/Providers/CpServiceProvider.php`
  - `src/Http/Middleware/CP/HandleInertiaRequests.php`
  - `src/Http/Middleware/CP/HandleAuthenticatedInertiaRequests.php`
  - `resources/views/layout.blade.php`
  - `resources/views/partials/scripts.blade.php`
- Docs:
  - https://statamic.dev/extending/control-panel
  - https://statamic.dev/getting-started/upgrade-guide/5-to-6
  - https://statamic.dev/extending/utilities
  - https://statamic.dev/extending/field-actions

### 6) Other addon patterns in this workspace

There are no other local addons in this sandbox using a stronger alternative pattern to copy directly. The strongest practical reference is Statamic core CP behavior and official docs.

## Option Analysis

### Option A: Keep `provideToScript`, re-trigger on SPA navigation

Pros:

- Minimal short-term code movement.

Cons:

- Requires brittle client-side navigation detection and manual re-sync.
- Easy to duplicate/stack actions (`$fieldActions` has no official “remove”).
- Fights CP architecture instead of aligning with it.
- Weak v6 posture.

Verdict: **Reject**.

### Option B: Dedicated CP API endpoint, frontend fetches per context

Pros:

- Explicit, robust data contract.
- Works in v5 and v6.

Cons:

- Extra request(s) and caching/invalidation logic.
- Still needs registry dedupe strategy to avoid duplicate field actions.
- More moving parts than necessary if field config already has what we need.

Verdict: **Viable fallback**, but not the best primary design.

### Option C: Attach config to existing Statamic blueprint/fieldtype responses

Pros:

- No extra round-trip.

Cons:

- High coupling to core response/resource/controller internals.
- Harder upgrade path and larger surface area.

Verdict: **Reject** for maintainability.

### Option D: Field-config-driven actions + global action catalog (discovered option)

Pros:

- Uses existing field-action payload (`config`, `handle`, `store`, etc.) documented by Statamic.
- No per-page script injection required.
- No per-navigation fetch required (unless chosen for catalog delivery).
- Natural fit with v5 dynamic publish updates and v6 Inertia.

Cons:

- Requires a one-time redesign of registration logic.

Verdict: **Recommend**.

## Recommended Approach

### Core idea

- Treat each field’s `config` (already present in publish payloads) as the source of truth for:
  - enabled/disabled state
  - selected action handles
  - source/mode options
- Register candidate field actions once per fieldtype component using a global action catalog.
- Use `visible(payload)` to decide if each action should render for the current field instance.

This removes dependency on page-level `magicFields` snapshots.

### Catalog transport choice

Preferred:

- Keep catalog delivery lightweight and global (single payload), not per-blueprint.
- Either:
  - static script bootstrap (`provideToScript`) for this **global** catalog only, or
  - CP endpoint fetched once and cached in memory.

If we want full decoupling from script injection, use the CP endpoint. If we optimize for least change, static bootstrap is acceptable because data is no longer page-context-sensitive.

## High-Level Implementation Plan (No Code Yet)

### Backend

1. Remove per-blueprint script injection flow.

- Remove listener registrations from `src/ServiceProvider.php`.
- Remove `ProvideEntryMagicActionsToScript` and `ProvideAssetMagicActionsToScript` usage.
- Remove asset-browse view composer hook that force-injects script config.

2. Replace `MagicFieldsConfigBuilder` role.

- Introduce an action catalog builder service (or repurpose existing builder) that outputs:
  - fieldtype component name
  - list of action descriptors (`handle`, `title`, `type`, `icon`, `acceptedMimeTypes`)

3. Deliver catalog globally.

- Option 1 (minimal change): `Statamic::provideToScript(['magicActionCatalog' => ...])` once per CP request.
- Option 2 (decoupled): add CP route/controller endpoint under `routes/cp.php` returning JSON catalog.

### Frontend

4. Refactor registration logic in `resources/js/addon.ts`.

- Register actions from catalog once per component/action handle.
- Remove dependency on `window.StatamicConfig.magicFields`.
- Visibility logic should use payload `config` + action handle membership.
- Ensure duplicate registration guard (idempotent boot).

5. Keep existing action execution paths.

- Current `executeCompletion/executeVision/executeTranscription` paths can remain.

### Tests

6. Replace behavior coverage.

- Deprecate tests asserting HTML contains `magicFields`.
- Add tests for:
  - catalog availability contract
  - visibility behavior from field config
  - no duplicate action registration across navigation/localization changes

## Why Other Approaches Were Rejected

- A: brittle patching over stale bootstrap semantics.
- B: technically good but unnecessary complexity for data we already have in field payloads.
- C: too coupled to internals and upgrade-hostile.

## Risks and Unknowns

- Need a stable fieldtype-class -> component mapping for catalog generation.
- Must verify custom/third-party fieldtypes with appended config still expose expected `component()`.
- Duplicate action-handle collisions across components need deterministic keys.
- If action metadata becomes context-dependent in future, a hybrid with on-demand endpoint may be needed.

## Rollout Notes

- Implement behind a temporary feature flag if desired (`config/statamic/magic-actions.php`) for safe migration.
- Validate with entry localization switching and assets browse/edit transitions before removing legacy listeners.
