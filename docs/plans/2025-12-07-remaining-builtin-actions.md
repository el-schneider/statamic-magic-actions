# Remaining Built-In Actions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add the remaining 6 built-in AI actions to the test application blueprint and verify they work correctly in the Statamic control panel.

**Architecture:** The addon provides 9 pre-configured AI actions. Three have been implemented and tested (extract-meta-description, propose-title, extract-tags). This plan covers integrating the remaining 6 actions into the test blueprint by:
1. Adding fields with magic action configuration for each action type
2. Testing each action in the control panel with real data
3. Documenting which field types support each action
4. Ensuring data transformers handle response formats correctly

**Tech Stack:** Statamic CMS, Laravel, Vue.js, OpenAI API via Prism PHP, Playwright for testing

**Action Types Summary:**
- **Text Completion:** create-teaser (textarea source → textarea output)
- **Vision (Image):** alt-text (assets source → text output), extract-assets-tags (assets source → terms output)
- **Transcription (Audio):** transcribe-audio (assets source → textarea output)
- **Complex:** assign-tags-from-taxonomies (requires taxonomy context - advanced, defer to follow-up)

---

## Task 1: Add Create Teaser Action to Blueprint

**Files:**
- Modify: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml:41-56`
- Test: Manual Playwright test in control panel

**Step 1: Review current blueprint structure**

Review the existing text/textarea field configurations:
```
File: ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
Lines: 15-45 (meta_description and title_suggestion fields)
```

Pattern to follow:
- Field type: `textarea`
- `magic_actions_enabled: true`
- `magic_actions_mode: replace` (for single-output actions)
- `magic_actions_action: create-teaser`
- `magic_actions_source: content`

**Step 2: Add create-teaser field to blueprint**

Edit `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml` and insert this field after the `title_suggestion` field (after line 31):

```yaml
          -
            handle: teaser
            field:
              magic_actions_enabled: true
              magic_actions_mode: replace
              magic_actions_action: create-teaser
              type: textarea
              display: 'Teaser'
              magic_actions_source: content
```

**Step 3: Verify blueprint syntax**

Run:
```bash
cd ../statamic-magic-actions-test
php artisan config:cache --ansi
```

Expected: No errors, blueprint loads successfully.

**Step 4: Test in control panel**

Navigate to: `http://statamic-magic-actions-test.test/cp/collections/pages/entries/aec0f27f-96e9-46cf-a330-f16b45904981`

1. Scroll to "Teaser" field
2. Click the magic wand button
3. Verify API call succeeds and returns teaser text
4. Confirm text appears in the field

Expected: Brief teaser text generated from content (50-100 words).

**Step 5: Commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: add create-teaser magic action field to test blueprint"
```

---

## Task 2: Add Assets Field to Blueprint for Vision Actions

**Files:**
- Modify: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml`
- Reference: `../statamic-magic-actions-test/resources/blueprints/assets/assets.yaml`

**Note:** Vision actions (alt-text, extract-assets-tags) require an assets field to select images. We need to add an assets field to the blueprint that can serve as the source.

**Step 1: Add assets field to blueprint**

Edit `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml` and add this field in the main tab after the content field (after line 45):

```yaml
          -
            handle: featured_image
            field:
              type: assets
              container: assets
              max_files: 1
              display: 'Featured Image'
```

**Step 2: Verify the assets container exists**

Run:
```bash
ls -la ../statamic-magic-actions-test/resources/blueprints/assets/
```

Expected: `assets.yaml` exists and container is configured.

**Step 3: Verify blueprint loads**

Run:
```bash
cd ../statamic-magic-actions-test
php artisan config:cache --ansi
```

Expected: No errors.

**Step 4: Test in control panel**

Navigate to the page edit screen and verify the "Featured Image" field appears and allows uploading/selecting assets.

**Step 5: Commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: add featured-image assets field to test blueprint"
```

---

## Task 3: Add Alt Text Magic Action Field

**Files:**
- Modify: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml`
- Reference: `addon/resources/actions/alt-text/prompt.blade.php`

**Step 1: Understand alt-text action requirements**

The alt-text action:
- Type: `vision` (image analysis)
- Input: image asset + optional text
- Output: descriptive alt text
- Works with: text, textarea fields

**Step 2: Add alt-text field to blueprint**

Edit `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml` and add after featured_image:

```yaml
          -
            handle: image_alt_text
            field:
              magic_actions_enabled: true
              magic_actions_mode: replace
              magic_actions_action: alt-text
              type: text
              display: 'Image Alt Text'
              magic_actions_source: featured_image
```

**Step 3: Verify blueprint syntax**

Run:
```bash
cd ../statamic-magic-actions-test
php artisan config:cache --ansi
```

Expected: No errors.

**Step 4: Test in control panel**

1. Navigate to page edit
2. Upload or select an image in "Featured Image" field
3. Scroll to "Image Alt Text" field
4. Click magic wand button
5. Verify API processes image and returns descriptive alt text

Expected: Detailed, accessible alt text description of the image (e.g., "A colorful sunset over mountains with trees in foreground").

**Step 5: Commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: add alt-text magic action for image descriptions"
```

---

## Task 4: Add Extract Assets Tags Magic Action Field

**Files:**
- Modify: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml`
- Reference: `addon/resources/actions/extract-assets-tags/prompt.blade.php`

**Step 1: Understand extract-assets-tags requirements**

The extract-assets-tags action:
- Type: `vision` (image analysis)
- Input: image asset + optional text context
- Output: comma-separated tags or JSON array
- Works with: tags, terms fields
- Transformer: Uses `tags` transformer with CSV parsing

**Step 2: Add assets-tags field to blueprint**

Edit `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml` and add after image_alt_text:

```yaml
          -
            handle: image_tags
            field:
              magic_actions_enabled: true
              magic_actions_mode: append
              magic_actions_action: extract-assets-tags
              type: terms
              display: 'Image Tags'
              magic_actions_source: featured_image
```

**Step 3: Verify blueprint syntax**

Run:
```bash
cd ../statamic-magic-actions-test
php artisan config:cache --ansi
```

Expected: No errors.

**Step 4: Test in control panel**

1. Navigate to page edit
2. Ensure an image is selected in "Featured Image" field
3. Scroll to "Image Tags" field
4. Click magic wand button
5. Verify API analyzes image and returns tags

Expected: 5-10 tags extracted from image (e.g., "sunset", "landscape", "mountains", "nature").

**Note:** The tags will be parsed by the existing `tags` transformer in `addon.ts` which handles CSV format and quoted strings.

**Step 5: Commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: add extract-assets-tags magic action for image tagging"
```

---

## Task 5: Add Transcription Field and Transcribe Audio Action

**Files:**
- Modify: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml`
- Reference: `addon/resources/actions/transcribe-audio/prompt.blade.php`

**Note:** Transcription requires an audio file asset. We'll add an audio assets field.

**Step 1: Add audio assets field to blueprint**

Edit `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml` and add a new assets field for audio:

```yaml
          -
            handle: audio_file
            field:
              type: assets
              container: assets
              max_files: 1
              accept: ['audio/*']
              display: 'Audio File'
```

**Step 2: Add transcribe-audio field to blueprint**

Add after audio_file:

```yaml
          -
            handle: audio_transcript
            field:
              magic_actions_enabled: true
              magic_actions_mode: replace
              magic_actions_action: transcribe-audio
              type: textarea
              display: 'Audio Transcript'
              magic_actions_source: audio_file
```

**Step 3: Verify blueprint syntax**

Run:
```bash
cd ../statamic-magic-actions-test
php artisan config:cache --ansi
```

Expected: No errors.

**Step 4: Test in control panel (when audio file available)**

1. Navigate to page edit
2. Upload or select an audio file in "Audio File" field
3. Scroll to "Audio Transcript" field
4. Click magic wand button
5. Verify API transcribes audio and returns text

Expected: Full transcription of audio content in textarea.

**Note:** This requires providing an audio file. You can use a short MP3 or WAV file for testing.

**Step 5: Commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: add transcribe-audio magic action for audio transcription"
```

---

## Task 6: Document Remaining Advanced Actions

**Files:**
- Create: `docs/ACTIONS.md` (new documentation file)

**Purpose:** Document the actions that require special handling or are deferred.

**Step 1: Create actions documentation file**

Create `docs/ACTIONS.md` with content:

```markdown
# Magic Actions Reference

## Built-in Actions

### Completion Actions (Text Processing)
- **extract-meta-description**: Generate SEO meta descriptions from content
- **propose-title**: Suggest alternative titles for content
- **extract-tags**: Extract relevant tags from content
- **create-teaser**: Create a brief teaser/summary of content

### Vision Actions (Image Analysis)
- **alt-text**: Generate descriptive alt text for images
- **extract-assets-tags**: Extract tags/keywords from image content

### Transcription Actions (Audio/Video)
- **transcribe-audio**: Transcribe audio files to text

### Advanced Actions (Requires Custom Setup)
- **assign-tags-from-taxonomies**: Assign tags from available taxonomy terms
  - **Status**: Requires custom implementation
  - **Reason**: Needs access to available taxonomy terms, not yet integrated
  - **Future**: Can be implemented with enhanced action context passing

## Field Type Compatibility

| Action | Type | Input Field | Output Field | Mode |
|--------|------|-------------|--------------|------|
| extract-meta-description | completion | textarea | textarea | replace |
| propose-title | completion | textarea | text | replace |
| extract-tags | completion | textarea | terms | append |
| create-teaser | completion | textarea | textarea | replace |
| alt-text | vision | assets | text | replace |
| extract-assets-tags | vision | assets | terms | append |
| transcribe-audio | transcription | assets (audio) | textarea | replace |
| assign-tags-from-taxonomies | completion | textarea | terms | append* |

*Requires taxonomy context - deferred

## Adding a Magic Action to a Field

### Step 1: Add magic_actions configuration
```yaml
-
  handle: field_handle
  field:
    magic_actions_enabled: true
    magic_actions_action: action-name
    magic_actions_source: source_field_handle
    magic_actions_mode: replace # or append
    type: field_type
    display: 'Field Label'
```

### Step 2: Ensure field type transformer exists
Check `resources/js/addon.ts` for field type transformer (text, tags, terms, textarea, bard, assets).

### Step 3: Test in control panel
- Navigate to entry edit page
- Click magic wand icon
- Verify API call and response handling

## Response Transformation

The frontend automatically transforms API responses based on field type:
- **text**: Extracts string value, unwraps nested `{data: "..."}`
- **textarea**: Same as text
- **tags/terms**: Parses quoted CSV format (`"tag1", "tag2", "tag3"`)
- **bard**: Appends as new paragraph block
- **assets**: Returns raw data (for alt text, metadata)

## Troubleshooting

**No job_id returned**: API endpoint not returning job tracking ID
**Timeout waiting for job**: Job processing took too long or failed
**Field shows [object Object]**: Response not being transformed by formatter
**Raw JSON in field**: CSV parser not handling response format

See addon.ts:processApiResponse() for response extraction logic.
```

**Step 2: Commit documentation**

```bash
git add docs/ACTIONS.md
git commit -m "docs: add comprehensive magic actions reference"
```

---

## Task 7: Verify All Actions in Test Application

**Files:**
- Blueprint: `../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml`

**Step 1: Full blueprint review**

Open the updated blueprint and verify all magic action fields are present and correctly configured:

```bash
cat ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml | grep -A5 "magic_actions"
```

Expected: 7 actions configured (extract-meta-description, propose-title, extract-tags, create-teaser, alt-text, extract-assets-tags, transcribe-audio).

**Step 2: Access test application**

Navigate to: `http://statamic-magic-actions-test.test/cp/collections/pages/entries/aec0f27f-96e9-46cf-a330-f16b45904981`

**Step 3: Test each action in sequence**

For each action:
1. Scroll to field
2. Verify field displays with magic wand button
3. Click button and wait for API response
4. Verify output appears in field with correct format

Actions to test:
- [ ] Extract Meta Description (should have content already)
- [ ] Propose Title (should have content already)
- [ ] Extract Tags (should have content already)
- [ ] Create Teaser (new)
- [ ] Alt Text (requires uploaded image)
- [ ] Extract Assets Tags (requires uploaded image)
- [ ] Transcribe Audio (requires uploaded audio)

**Step 4: Document test results**

For each action, note:
- Request success/failure
- Response format and content
- Field update success
- Any transformations applied

**Step 5: Final commit**

```bash
git add ../statamic-magic-actions-test/resources/blueprints/collections/pages/page.yaml
git commit -m "feat: integrate all 7 built-in magic actions in test blueprint"
```

---

## Implementation Notes

### Data Flow for Each Action Type

**Text Completion (extract-meta-description, propose-title, extract-tags, create-teaser):**
1. User clicks magic wand on field
2. Source field text extracted via `extractText()`
3. POST to `/completion` endpoint with `action` and `text` parameters
4. Server queues job with Prism provider
5. Frontend polls `/status/{jobId}` until complete
6. Response contains `{data: "result"}` structure
7. `text` transformer unwraps and extracts string
8. Field updated with result

**Vision Actions (alt-text, extract-assets-tags):**
1. User clicks magic wand on field
2. Asset ID extracted from URL or source field
3. POST to `/vision` endpoint with `action` and `asset_path` parameters
4. Server loads image, passes to vision model
5. Frontend polls `/status/{jobId}` until complete
6. Response contains analyzed content
7. `text` or `tags` transformer formats result
8. Field updated

**Transcription (transcribe-audio):**
1. User clicks magic wand on field
2. Audio asset ID extracted from source field
3. POST to `/transcribe` endpoint with `action` and `asset_path`
4. Server loads audio, passes to transcription model
5. Frontend polls `/status/{jobId}` until complete
6. Response contains transcribed text
7. `text` transformer formats result
8. Field updated

### Frontend Code Reference

All action routing happens in `resources/js/addon.ts`:
- Lines 179-213: `generateFromPrompt()` method routes to correct endpoint
- Lines 94-118: `executeCompletion()` handles text actions
- Lines 121-133: `executeVision()` handles image analysis
- Lines 136-147: `executeTranscription()` handles audio
- Lines 236-300: `transformerMap` defines field type output formatters

### Potential Issues & Solutions

**Issue:** "Asset ID is required for vision requests"
- **Solution:** Ensure `magic_actions_source` points to a valid assets field

**Issue:** Tags appear as raw JSON string instead of individual items
- **Solution:** Verify `tags` transformer in transformerMap is applied (should use field.type)

**Issue:** API returns 422 Unprocessable Content
- **Solution:** Check that action name in blueprint matches action directory name (use hyphens, not underscores)

**Issue:** Timeout waiting for job
- **Solution:** Check job queue is running; verify OpenAI API key is valid and has quota

---

## Deferred: assign-tags-from-taxonomies

This action requires:
- Access to available taxonomy terms for the field
- Enhanced context passing from frontend to backend
- Custom resolver logic to map available terms

**Why Deferred:** Requires architectural changes to pass taxonomy context. Recommend as a follow-up story after core actions are stable.

**Implementation Path (future):**
1. Modify `generateFromPrompt()` to detect taxonomy fields
2. Load available terms for field's taxonomy
3. Pass as `available_tags` to prompt
4. Update action prompt to reference the variable
5. Test with taxonomy-aware field

---

## Success Criteria

- [ ] All 7 actions configured in test blueprint
- [ ] All text completion actions tested and working
- [ ] All vision actions tested with sample images
- [ ] Transcription action tested with sample audio
- [ ] All responses transformed correctly to field types
- [ ] Actions documentation created
- [ ] All changes committed to git

---

## Execution Strategy

**Recommended approach:** Complete Tasks 1-5 sequentially, testing each action immediately after adding to blueprint. This ensures quick feedback and early issue detection.

**Optional optimization:** Tasks 1, 2, 3, 4, 5 can be batched into a single blueprint edit, then tested all at once. However, sequential testing is recommended for clarity.
