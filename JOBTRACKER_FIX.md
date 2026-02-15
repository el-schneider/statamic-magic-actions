# JobTracker cache write failure (Issue #7)

## Findings

I reviewed `src/Services/JobTracker.php`.

### Current behavior

`JobTracker` writes/reads job state through:

- `cachePut()`
- `cacheGet()`

Both methods try to use tagged cache first:

- `Cache::tags([self::CACHE_TAG])->put(...)`
- `Cache::tags([self::CACHE_TAG])->get(...)`

and gate this via `supportsCacheTags()`.

If tagged access throws, the exception is caught and only logged, with no fallback attempt in that same call path. For writes, this means data can be dropped; for reads, this returns `null`, making jobs look missing.

### Why the guard is insufficient

`supportsCacheTags()` currently does:

```php
return method_exists(Cache::getStore(), 'tags');
```

This is a weak capability check. It can report "supported" for stores/wrappers where `tags()` exists but still fails at runtime (driver-specific behavior), causing `Cache::tags(...)` to throw.

Because `cachePut()` / `cacheGet()` swallow that throwable instead of retrying untagged, JobTracker silently fails.

---

## Options considered

### 1) Remove tag usage entirely

**Pros**

- Simplest and most reliable
- Fixes file-driver/default Laravel setups immediately
- No runtime probing or edge-case handling needed

**Cons**

- Loses tag grouping capability (currently not used elsewhere in `JobTracker`)

### 2) Graceful fallback when tags fail

**Pros**

- Keeps tag support for Redis/Memcached/etc.
- Works across more cache drivers

**Cons**

- Slightly more complexity
- Needs careful retry logic to avoid repeated exceptions

### 3) Different tracking mechanism (DB table, queue metadata, etc.)

**Pros**

- Most explicit and robust long-term

**Cons**

- Largest scope change
- Not needed to fix this bug

---

## Proposed fix

**Recommended:** Option **1** (remove cache tags in `JobTracker`).

Reason: `JobTracker` does not currently use tag-specific operations (e.g. tag flush/query). Tags add risk but no practical benefit here. Un-tagged keys are already namespaced via constants:

- `magic_actions_job_...`
- `magic_actions_batch_...`
- `magic_actions_batch_jobs_...`

So key-level isolation remains intact without tags.

### Concrete changes (not yet applied)

1. Remove `CACHE_TAG` constant (or leave unused temporarily).
2. Remove `supportsCacheTags()`.
3. Simplify cache calls:
   - `cacheGet()` -> `Cache::get($key)`
   - `cachePut()` -> `Cache::put($key, $value, $ttl)`
4. Keep logging in `catch`, but no tagged branch.

This removes the failure mode where tag calls throw and writes are silently lost.

---

## If you prefer to keep tags (alternative patch)

If Option 2 is preferred, the minimum safe behavior is:

- Try tagged operation first
- On tagged failure, log once and retry untagged in the same call
- Optionally memoize a `tagsUnavailable` flag for this process

Pseudo-flow for `cachePut()`:

1. if tags believed supported -> try tagged put
2. if tagged put throws -> log + try plain `Cache::put(...)`
3. if plain put throws -> log final failure

Same for `cacheGet()` (tagged read, then untagged read fallback).

This ensures runtime tag failures do not drop job state.

---

## Conclusion

Issue #7 is valid: JobTracker can silently lose state when tagged cache calls fail.

The safest fix with lowest complexity is to **remove cache tag usage in `JobTracker`** and use plain cache operations with existing key prefixes.
