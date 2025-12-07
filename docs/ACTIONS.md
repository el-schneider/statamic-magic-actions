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

## Deferred: assign-tags-from-taxonomies

This action requires:
- Access to available taxonomy terms for the field
- Enhanced context passing from frontend to backend
- Custom resolver logic to map available terms

**Why Deferred**: Requires architectural changes to pass taxonomy context. Recommend as a follow-up story after core actions are stable.

**Implementation Path (future)**:
1. Modify `generateFromPrompt()` to detect taxonomy fields
2. Load available terms for field's taxonomy
3. Pass as `available_tags` to prompt
4. Update action prompt to reference the variable
5. Test with taxonomy-aware field

## Success Criteria

- [x] All 7 actions configured in test blueprint
- [ ] All text completion actions tested and working
- [ ] All vision actions tested with sample images
- [ ] Transcription action tested with sample audio
- [ ] All responses transformed correctly to field types
- [ ] Actions documentation created
- [ ] All changes committed to git
