# Vision Actions Asset Handling Fix

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix vision actions (alt-text, extract-assets-tags) by resolving asset paths to URLs before Blade template rendering.

**Architecture:**
Add asset URL resolution to ProcessPromptJob that converts Statamic asset paths (format: `container::filename`) to public URLs using the Asset facade. Resolve assets early in the job lifecycle, inject the URL as `$image` variable, and pass it to ActionLoader for template rendering. This ensures Blade templates have access to `{{ $image }}` during rendering, not after.

**Tech Stack:**
- Laravel/Statamic Asset facade (`Statamic\Facades\Asset`)
- Blade templating engine
- Prism PHP for AI provider abstraction
- PHPUnit/Pest for testing

---

## Understanding Asset Paths in Statamic

**Asset Path Format:** `container::filename`
- Example: `assets::18546.jpg` means file `18546.jpg` in `assets` container
- Use `Asset::find('assets::18546.jpg')` to resolve to Asset object
- Asset object has `->url()` method returning public URL
- Asset object has `->alt` property for alt text metadata

**Key Facts from Statamic Docs:**
- Asset facade: `use Statamic\Facades\Asset;`
- Asset::find() returns the asset or null if not found
- URL is accessible via `$asset->url()` method
- Works with any configured asset container

---

## Task 1: Write Failing Test for Asset URL Resolution

**Files:**
- Modify: `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Write the failing test**

Add this test to the ProcessPromptJobTest class:

```php
public function test_resolves_asset_path_to_url_for_vision_actions()
{
    // Mock an asset that can be found
    $assetMock = $this->mock('Statamic\Assets\Asset');
    $assetMock->shouldReceive('url')->andReturn('https://example.test/assets/18546.jpg');

    // Mock the Asset facade to return our mock
    Asset::shouldReceive('find')
        ->with('assets::18546.jpg')
        ->andReturn($assetMock);

    // Create and dispatch the job
    $job = new ProcessPromptJob(
        'test-job-123',
        'alt-text',
        ['text' => 'Describe this image'],
        null,
        'assets::18546.jpg'  // This is the assetPath parameter
    );

    // Mock ActionLoader to verify $image variable is passed
    $actionLoaderMock = $this->mock(ActionLoader::class);
    $actionLoaderMock->shouldReceive('load')
        ->with('alt-text', [
            'text' => 'Describe this image',
            'image' => 'https://example.test/assets/18546.jpg'  // Should be resolved
        ])
        ->andReturn([
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4-vision-preview',
            'parameters' => [],
            'systemPrompt' => 'Test system',
            'userPrompt' => 'Test prompt',
        ]);

    // Mock Prism facade
    Prism::shouldReceive('text')
        ->andReturnSelf();
    Prism::shouldReceive('using')
        ->andReturnSelf();
    Prism::shouldReceive('withSystemPrompt')
        ->andReturnSelf();
    Prism::shouldReceive('withPrompt')
        ->andReturnSelf();
    Prism::shouldReceive('asText')
        ->andReturn((object)['text' => 'Generated alt text']);

    // Execute the job
    $job->handle($actionLoaderMock);

    // Verify cache has successful result
    $cached = Cache::get('magic_actions_job_test-job-123');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toHaveKey('text');
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_resolves_asset_path_to_url_for_vision_actions -v`

Expected output:
```
FAILED  Tests\Unit\ProcessPromptJobTest > test_resolves_asset_path_to_url_for_vision_actions
Expected invocation: Asset::find()
  with arguments: assets::18546.jpg
But was never called
```

The test fails because we haven't implemented asset resolution yet.

**Step 3: Commit the failing test**

```bash
git add tests/Unit/ProcessPromptJobTest.php
git commit -m "test: add failing test for asset URL resolution in vision actions"
```

---

## Task 2: Add resolveAssetUrl() Private Method to ProcessPromptJob

**Files:**
- Modify: `src/Jobs/ProcessPromptJob.php:140-160` (add new method)

**Step 1: Read the current file to understand structure**

The file should already have imports. Verify the Asset facade import exists around line 20.

**Step 2: Add the import if missing**

Check that this import exists near the top of the file (after line 1):

```php
use Statamic\Facades\Asset;
```

If not present, add it with the other use statements.

**Step 3: Write the resolveAssetUrl() private method**

Add this method to the ProcessPromptJob class (after the existing private methods, around line 880-910):

```php
/**
 * Resolve an asset path to its public URL
 *
 * Converts Statamic asset paths (format: container::filename) to public URLs.
 * Returns null if asset cannot be found.
 *
 * @param string $assetPath Asset path in format "container::filename"
 * @return string|null The public URL of the asset, or null if not found
 */
private function resolveAssetUrl(string $assetPath): ?string
{
    try {
        $asset = Asset::find($assetPath);

        if (!$asset) {
            Log::warning('Asset not found for vision action', [
                'asset_path' => $assetPath,
                'job_id' => $this->jobId,
            ]);
            return null;
        }

        return $asset->url();
    } catch (Exception $e) {
        Log::warning('Error resolving asset URL for vision action', [
            'asset_path' => $assetPath,
            'job_id' => $this->jobId,
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}
```

**Step 4: Run test to verify it still fails (but for different reason)**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_resolves_asset_path_to_url_for_vision_actions -v`

Expected: Test still fails because we haven't called this method from handle() yet.

**Step 5: Commit the helper method**

```bash
git add src/Jobs/ProcessPromptJob.php
git commit -m "feat: add resolveAssetUrl() helper method to ProcessPromptJob"
```

---

## Task 3: Modify ProcessPromptJob::handle() to Resolve Asset Before ActionLoader

**Files:**
- Modify: `src/Jobs/ProcessPromptJob.php:45-77` (handle method)

**Step 1: Understand the current handle() method**

Read lines 45-77 of ProcessPromptJob::handle(). The flow should be:
1. Set processing status in cache
2. Call ActionLoader::load() with variables
3. Route to handleTextPrompt or handleAudioPrompt based on type
4. Cache the result

**Step 2: Add asset resolution logic before ActionLoader call**

Find this section in handle() (around line 52):
```php
$promptData = $actionLoader->load($this->action, $this->variables);
```

Replace it with:

```php
// Resolve asset path to URL if this is a vision action with an asset path
if ($this->assetPath && !isset($this->variables['image'])) {
    $imageUrl = $this->resolveAssetUrl($this->assetPath);
    if ($imageUrl) {
        $this->variables['image'] = $imageUrl;
    }
}

$promptData = $actionLoader->load($this->action, $this->variables);
```

**Step 3: Verify the logic**

The code should:
- Check if `$this->assetPath` exists (not null)
- Check that `$this->variables` doesn't already have an 'image' key (don't override)
- Call `resolveAssetUrl()` to get the URL
- Only add to variables if URL was successfully resolved
- Pass variables to ActionLoader

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_resolves_asset_path_to_url_for_vision_actions -v`

Expected:
```
PASSED  Tests\Unit\ProcessPromptJobTest > test_resolves_asset_path_to_url_for_vision_actions
```

**Step 5: Commit the change**

```bash
git add src/Jobs/ProcessPromptJob.php
git commit -m "feat: resolve asset path to URL before ActionLoader in ProcessPromptJob"
```

---

## Task 4: Write Test for Variable Precedence (Explicit vs Auto-Resolved)

**Files:**
- Modify: `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Write the failing test**

Add this test to ProcessPromptJobTest:

```php
public function test_explicit_image_variable_takes_precedence_over_asset_path()
{
    // When both assetPath and explicit image variable are provided,
    // the explicit image variable should be used (not overridden)

    $explicitImageUrl = 'https://example.test/explicit.jpg';

    $actionLoaderMock = $this->mock(ActionLoader::class);
    $actionLoaderMock->shouldReceive('load')
        ->with('alt-text', [
            'text' => 'Describe',
            'image' => $explicitImageUrl  // Should use explicit URL
        ])
        ->andReturn([
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4-vision-preview',
            'parameters' => [],
            'systemPrompt' => 'System',
            'userPrompt' => 'Prompt',
        ]);

    // Mock Prism
    Prism::shouldReceive('text')->andReturnSelf();
    Prism::shouldReceive('using')->andReturnSelf();
    Prism::shouldReceive('withSystemPrompt')->andReturnSelf();
    Prism::shouldReceive('withPrompt')->andReturnSelf();
    Prism::shouldReceive('asText')->andReturn((object)['text' => 'Result']);

    // Create job with both explicit image AND assetPath
    $job = new ProcessPromptJob(
        'test-job-456',
        'alt-text',
        [
            'text' => 'Describe',
            'image' => $explicitImageUrl  // Explicit variable
        ],
        null,
        'assets::some-other-image.jpg'  // Different assetPath (should be ignored)
    );

    // Execute
    $job->handle($actionLoaderMock);

    // Verify the explicit image URL was preserved
    $cached = Cache::get('magic_actions_job_test-job-456');
    expect($cached['status'])->toBe('completed');
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_explicit_image_variable_takes_precedence_over_asset_path -v`

Expected: Test fails because ActionLoader is called with unexpected arguments (resolveAssetUrl might override the explicit image).

**Step 3: Verify handle() method already has the check**

The code we added in Task 3 already includes:
```php
if ($this->assetPath && !isset($this->variables['image'])) {
```

This prevents overriding explicit image variables. The test should now pass.

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_explicit_image_variable_takes_precedence_over_asset_path -v`

Expected:
```
PASSED  Tests\Unit\ProcessPromptJobTest > test_explicit_image_variable_takes_precedence_over_asset_path
```

**Step 5: Commit the test**

```bash
git add tests/Unit/ProcessPromptJobTest.php
git commit -m "test: verify explicit image variables are not overridden by asset resolution"
```

---

## Task 5: Write Test for Missing Asset Handling

**Files:**
- Modify: `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Write the failing test for missing asset**

Add this test:

```php
public function test_handles_missing_asset_gracefully()
{
    // When asset path is provided but asset doesn't exist,
    // job should proceed without the image variable

    // Mock Asset facade to return null (not found)
    Asset::shouldReceive('find')
        ->with('assets::nonexistent.jpg')
        ->andReturn(null);

    $actionLoaderMock = $this->mock(ActionLoader::class);
    $actionLoaderMock->shouldReceive('load')
        ->with('alt-text', [
            'text' => 'Describe'
            // No 'image' key since asset not found
        ])
        ->andReturn([
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4-vision-preview',
            'parameters' => [],
            'systemPrompt' => 'System',
            'userPrompt' => 'Prompt',
        ]);

    // Mock Prism
    Prism::shouldReceive('text')->andReturnSelf();
    Prism::shouldReceive('using')->andReturnSelf();
    Prism::shouldReceive('withSystemPrompt')->andReturnSelf();
    Prism::shouldReceive('withPrompt')->andReturnSelf();
    Prism::shouldReceive('asText')->andReturn((object)['text' => 'Result']);

    // Create job with non-existent asset
    $job = new ProcessPromptJob(
        'test-job-789',
        'alt-text',
        ['text' => 'Describe'],
        null,
        'assets::nonexistent.jpg'
    );

    // Execute - should not throw, should complete with null image
    $job->handle($actionLoaderMock);

    // Verify job completed (with or without image)
    $cached = Cache::get('magic_actions_job_test-job-789');
    expect($cached['status'])->toBe('completed');
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_handles_missing_asset_gracefully -v`

Expected: Fails because ActionLoader expects no 'image' key but we're not providing it.

**Step 3: The handle() code already supports this**

The `resolveAssetUrl()` method we added returns null if asset not found. The handle() code we added only sets `$this->variables['image']` if URL is successfully resolved:

```php
if ($imageUrl) {
    $this->variables['image'] = $imageUrl;
}
```

So the test should pass.

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_handles_missing_asset_gracefully -v`

Expected:
```
PASSED  Tests\Unit\ProcessPromptJobTest > test_handles_missing_asset_gracefully
```

**Step 5: Commit the test**

```bash
git add tests/Unit/ProcessPromptJobTest.php
git commit -m "test: verify missing assets are handled gracefully in vision actions"
```

---

## Task 6: Write Integration Test for Alt-Text Vision Action

**Files:**
- Modify: `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Write the end-to-end alt-text test**

Add this integration test:

```php
public function test_alt_text_vision_action_with_asset()
{
    // Full integration test: asset resolution -> template rendering -> Prism response

    // Mock a real asset
    $assetMock = $this->mock('Statamic\Assets\Asset');
    $assetMock->shouldReceive('url')->andReturn('https://example.test/images/18546.jpg');

    Asset::shouldReceive('find')
        ->with('assets::18546.jpg')
        ->andReturn($assetMock);

    // Mock ActionLoader to verify it receives proper variables and returns prompt data
    $actionLoaderMock = $this->mock(ActionLoader::class);
    $actionLoaderMock->shouldReceive('load')
        ->with('alt-text', Mockery::on(function($variables) {
            // Verify variables include resolved image URL
            return isset($variables['image'])
                && $variables['image'] === 'https://example.test/images/18546.jpg';
        }))
        ->andReturn([
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4-vision-preview',
            'parameters' => [
                'temperature' => 0.7,
                'max_tokens' => 1000,
            ],
            'systemPrompt' => 'Generate alt text for images',
            'userPrompt' => 'Image: https://example.test/images/18546.jpg',
            'schema' => null,
        ]);

    // Mock Prism text response
    Prism::shouldReceive('text')->andReturnSelf();
    Prism::shouldReceive('using')->andReturnSelf();
    Prism::shouldReceive('withSystemPrompt')->andReturnSelf();
    Prism::shouldReceive('withPrompt')->andReturnSelf();
    Prism::shouldReceive('usingTemperature')->andReturnSelf();
    Prism::shouldReceive('withMaxTokens')->andReturnSelf();
    Prism::shouldReceive('asText')->andReturn((object)[
        'text' => 'A colorful sunset over mountains with trees in foreground'
    ]);

    // Create and execute job
    $job = new ProcessPromptJob(
        'integration-test-alt-text',
        'alt-text',
        ['text' => 'Context about image'],
        null,
        'assets::18546.jpg'
    );

    $job->handle($actionLoaderMock);

    // Verify result is cached correctly
    $cached = Cache::get('magic_actions_job_integration-test-alt-text');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toHaveKey('text');
    expect($cached['data']['text'])->toContain('sunset');
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_alt_text_vision_action_with_asset -v`

Expected: Fails if the asset resolution isn't working yet.

**Step 3: Run test to verify it passes**

With Tasks 3-4 implemented, this test should pass.

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_alt_text_vision_action_with_asset -v`

Expected:
```
PASSED  Tests\Unit\ProcessPromptJobTest > test_alt_text_vision_action_with_asset
```

**Step 4: Commit the integration test**

```bash
git add tests/Unit/ProcessPromptJobTest.php
git commit -m "test: add integration test for alt-text vision action with asset resolution"
```

---

## Task 7: Write Integration Test for Extract-Assets-Tags Vision Action

**Files:**
- Modify: `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Write the extract-assets-tags test**

Add this test:

```php
public function test_extract_assets_tags_vision_action_with_asset()
{
    // Full integration test for tag extraction from image

    // Mock asset
    $assetMock = $this->mock('Statamic\Assets\Asset');
    $assetMock->shouldReceive('url')->andReturn('https://example.test/sunset.jpg');

    Asset::shouldReceive('find')
        ->with('assets::sunset.jpg')
        ->andReturn($assetMock);

    // Mock ActionLoader
    $actionLoaderMock = $this->mock(ActionLoader::class);
    $actionLoaderMock->shouldReceive('load')
        ->with('extract-assets-tags', Mockery::on(function($variables) {
            return isset($variables['image'])
                && $variables['image'] === 'https://example.test/sunset.jpg';
        }))
        ->andReturn([
            'type' => 'text',
            'provider' => 'openai',
            'model' => 'gpt-4-vision-preview',
            'parameters' => [
                'temperature' => 0.5,
                'max_tokens' => 500,
            ],
            'systemPrompt' => 'Extract tags from image',
            'userPrompt' => 'Analyze image and extract tags',
            'schema' => null,
        ]);

    // Mock Prism response with CSV-formatted tags
    Prism::shouldReceive('text')->andReturnSelf();
    Prism::shouldReceive('using')->andReturnSelf();
    Prism::shouldReceive('withSystemPrompt')->andReturnSelf();
    Prism::shouldReceive('withPrompt')->andReturnSelf();
    Prism::shouldReceive('usingTemperature')->andReturnSelf();
    Prism::shouldReceive('withMaxTokens')->andReturnSelf();
    Prism::shouldReceive('asText')->andReturn((object)[
        'text' => '"sunset", "landscape", "mountains", "nature", "golden hour"'
    ]);

    // Execute job
    $job = new ProcessPromptJob(
        'integration-test-tags',
        'extract-assets-tags',
        [],
        null,
        'assets::sunset.jpg'
    );

    $job->handle($actionLoaderMock);

    // Verify result
    $cached = Cache::get('magic_actions_job_integration-test-tags');
    expect($cached['status'])->toBe('completed');
    expect($cached['data'])->toHaveKey('text');
    expect($cached['data']['text'])->toContain('sunset');
}
```

**Step 2: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php::test_extract_assets_tags_vision_action_with_asset -v`

Expected:
```
PASSED  Tests\Unit\ProcessPromptJobTest > test_extract_assets_tags_vision_action_with_asset
```

**Step 3: Commit the test**

```bash
git add tests/Unit/ProcessPromptJobTest.php
git commit -m "test: add integration test for extract-assets-tags vision action"
```

---

## Task 8: Run Full Test Suite and Fix Any Failures

**Files:**
- Test: All tests in `tests/Unit/ProcessPromptJobTest.php`

**Step 1: Run all ProcessPromptJob tests**

Run: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php -v`

Expected: All tests pass, including the new vision action tests.

**Step 2: Run full test suite**

Run: `./vendor/bin/pest`

Expected: All tests pass. If any tests fail:
- Read the failure message carefully
- Fix the implementation, not the test
- Re-run the specific failing test
- Commit the fix with a clear message

**Step 3: Verify no other tests were broken**

Check that:
- ActionLoaderTest still passes
- ActionsControllerTest still passes
- Any other existing tests still pass

Run: `./vendor/bin/pest tests/Unit/ -v`

Expected: All unit tests pass.

**Step 4: Commit test suite verification**

```bash
git add -A
git commit -m "test: verify all tests pass after vision action asset handling implementation"
```

---

## Task 9: Test Vision Actions in Control Panel via Playwright

**Files:**
- Manual testing via Playwright MCP in control panel

**Step 1: Navigate to test page in control panel**

Use Playwright MCP:
```
Navigate to: http://statamic-magic-actions-test.test/cp/collections/pages/entries/aec0f27f-96e9-46cf-a330-f16b45904981
Expected: Entry edit form loads with Featured Image field and Image Alt Text field
```

**Step 2: Select an image in Featured Image field**

The image `18546.jpg` should already be in the assets container.

Expected: Image appears as selected asset.

**Step 3: Click alt-text magic wand button**

Use Playwright MCP to click the magic wand dropdown for Image Alt Text field.

Expected: Action menu appears with "Alt Text" option.

**Step 4: Click Alt Text action**

Execute the alt-text action.

Expected:
- Request goes to `/!/statamic-magic-actions/vision` endpoint
- Job is queued
- Frontend polls `/!/statamic-magic-actions/status/{jobId}`
- Result returns with alt text
- Field is populated with generated alt text

Check browser console for logs showing:
- Job creation with asset path
- Polling for job status
- Job completion
- No errors about "Undefined variable $image"

**Step 5: Test Extract Assets Tags action**

Click magic wand for Image Tags field.

Expected:
- Same flow as alt-text
- Returns array of tags
- Tags appear in the field as individual items

**Step 6: Verify no errors in logs**

Check:
- Browser console: No JavaScript errors
- Server logs: No PHP errors about undefined variables
- Cache: Job results show proper structure

**Step 7: Commit verification notes**

```bash
git commit -m "test: manual verification of vision actions in control panel - alt-text and extract-assets-tags working correctly"
```

---

## Task 10: Test Transcribe Audio Action in Control Panel

**Files:**
- Manual testing via Playwright MCP

**Step 1: Select audio file**

The audio file `transcribing_1.mp3` should already be in the assets container.

Navigate to Audio File field, select the mp3 file.

Expected: Audio file appears as selected asset.

**Step 2: Click transcribe-audio magic wand button**

Use Playwright to click the magic wand for Audio Transcript field.

Expected: Same flow as vision actions.

**Step 3: Verify transcription result**

Expected:
- Job completes
- Transcript appears in Audio Transcript field
- No errors about asset handling

**Step 4: Commit verification**

```bash
git commit -m "test: manual verification of transcribe-audio action in control panel"
```

---

## Summary

**Total Changes: 3 files modified**

1. **src/Jobs/ProcessPromptJob.php**
   - Add `resolveAssetUrl()` private method (20 lines)
   - Modify `handle()` to resolve assets before ActionLoader (4 lines)

2. **tests/Unit/ProcessPromptJobTest.php**
   - Add 6 new test methods (120+ lines total)
   - Tests cover: resolution, precedence, error handling, integration

3. **Manual testing**
   - Control panel UI testing of all vision and transcription actions

**Key Architectural Points:**

- Asset resolution happens EARLY: immediately after cache setup, before ActionLoader
- Variables are injected with resolved URL: `$this->variables['image']` gets the URL
- Blade templates receive the variable: `{{ $image }}` is available during rendering
- Explicit variables are never overridden: check prevents replacing user-provided values
- Graceful error handling: null returns on missing assets, logging for debugging

**Commits Pattern:**
- One failing test
- Implementation of feature
- One test per behavior
- Integration tests last
- Manual verification final

---

## Verification Checklist

- [ ] All unit tests pass: `./vendor/bin/pest tests/Unit/ProcessPromptJobTest.php`
- [ ] Full test suite passes: `./vendor/bin/pest`
- [ ] Alt-text action works in control panel
- [ ] Extract-assets-tags action works in control panel
- [ ] Transcribe-audio action works in control panel
- [ ] No console errors in browser
- [ ] No PHP errors in server logs
- [ ] Asset resolution logs appear (check for warnings if asset not found)
- [ ] All commits are clean with descriptive messages
