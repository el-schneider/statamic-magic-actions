<?php

declare(strict_types=1);

return [
    // Field configuration (FieldConfigService)
    'field_section' => 'Magic Actions',
    'field_enabled' => 'Enabled',
    'field_source' => 'Source',
    'field_source_instructions' => 'The field that contains the content to be processed by Magic Actions.',
    'field_source_placeholder' => 'content',
    'field_mode' => 'Mode',
    'field_mode_instructions' => 'Whether to append or replace to the existing content.',
    'field_mode_append' => 'Append',
    'field_mode_replace' => 'Replace',
    'field_actions' => 'Actions',

    // Bulk action result messages (DynamicBulkAction)
    'bulk_target_field' => 'Target Field',
    'bulk_select_field' => 'Select the field that should receive the result.',
    'bulk_no_items' => 'No items were processed.',
    'bulk_queued' => 'Queued :title for :count item.|Queued :title for :count items.',
    'bulk_skipped' => ':count item was skipped.|:count items were skipped.',
    'bulk_failed' => ':count item failed to queue.|:count items failed to queue.',
    'bulk_action_not_found' => 'Action not found.',

    // Action titles
    'action_alt_text' => 'Alt Text',
    'action_assign_tags' => 'Assign Tags from Taxonomies',
    'action_create_teaser' => 'Create Teaser',
    'action_extract_assets_tags' => 'Extract Tags',
    'action_extract_meta_description' => 'Extract Meta Description',
    'action_extract_tags' => 'Extract Tags',
    'action_image_caption' => 'Image Caption',
    'action_propose_title' => 'Propose Title',
    'action_transcribe_audio' => 'Transcribe Audio',

    // Bulk confirmation text (DynamicBulkAction / individual actions)
    'confirm_alt_text' => 'Generate alt text for this asset?|Generate alt text for these :count assets?',
    'confirm_assign_tags' => 'Assign tags for this entry?|Assign tags for these :count entries?',
    'confirm_create_teaser' => 'Create a teaser for this entry?|Create teasers for these :count entries?',
    'confirm_extract_assets_tags' => 'Extract tags for this asset?|Extract tags for these :count assets?',
    'confirm_extract_meta_description' => 'Generate a meta description for this entry?|Generate meta descriptions for these :count entries?',
    'confirm_extract_tags' => 'Extract tags for this entry?|Extract tags for these :count entries?',
    'confirm_image_caption' => 'Generate caption for this asset?|Generate captions for these :count assets?',
    'confirm_propose_title' => 'Propose a title for this entry?|Propose titles for these :count entries?',
    'confirm_transcribe_audio' => 'Transcribe this asset?|Transcribe these :count assets?',

    // Bulk button text (individual actions)
    'button_alt_text' => 'Generate Alt Text|Generate Alt Text for :count Assets',
    'button_assign_tags' => 'Assign Tags|Assign Tags for :count Entries',
    'button_create_teaser' => 'Create Teaser|Create Teasers for :count Entries',
    'button_extract_assets_tags' => 'Extract Tags|Extract Tags for :count Assets',
    'button_extract_meta_description' => 'Generate Meta Description|Generate Meta Descriptions for :count Entries',
    'button_extract_tags' => 'Extract Tags|Extract Tags for :count Entries',
    'button_image_caption' => 'Generate Caption|Generate Captions for :count Assets',
    'button_propose_title' => 'Propose Title|Propose Titles for :count Entries',
    'button_transcribe_audio' => 'Transcribe Audio|Transcribe Audio for :count Assets',

    // Settings page (Settings/Blueprint.php)
    'settings_global' => 'Global Settings',
    'settings_system_prompt' => 'Global System Prompt',
    'settings_system_prompt_instructions' => 'This prompt will be prepended to all action system prompts. Use it to describe your brand voice, style guidelines, or other global context.',
    'settings_default_models' => 'Default Models',
    'settings_default_models_base' => 'Default model to use for each capability when no action-specific override is set.',
    'settings_no_providers' => 'No providers configured. Add providers under `statamic.magic-actions.providers` to enable model selection.',
    'settings_no_api_keys' => '**No API keys configured.** Add provider API keys to your `.env` file (e.g. :keys) to enable model selection.',
    'settings_unlock_providers' => 'To unlock more providers, add :keys to your `.env` file.',
    'settings_model_display' => ':type Model',
    'settings_model_instructions' => 'Default model for :type actions.',
    'settings_model_placeholder' => 'Use config default',
    'settings_saved' => 'Settings saved',

    // CLI command (MagicRunCommand)
    'cli_overwrite_conflict' => 'Use either --overwrite or --no-overwrite, not both.',
    'cli_queue_conflict' => 'Use either --queue or --no-queue, not both.',
    'cli_missing_field' => 'Missing required option: --field=',
    'cli_missing_target' => 'Provide at least one target via --collection=, --entry=, or --asset=',
    'cli_action_not_found' => "Action ':action' not found. Available actions: :available. Check config/statamic/magic-actions.php.",
    'cli_no_targets' => 'No targets resolved.',
    'cli_could_not_determine_action' => 'Could not determine action.',
    'cli_dry_run' => 'Dry run: no changes will be made.',
    'cli_dry_run_empty' => 'No targets would be processed.',
    'cli_job_dispatched' => 'Job dispatched.',
    'cli_completed_sync' => 'Completed synchronously.',
    'cli_field_has_value' => 'Field already has a value. Use --overwrite to force.',
    'cli_cannot_execute' => 'Action cannot run for this target/field.',
    'cli_no_blueprint' => 'Target has no blueprint; unable to resolve field action config.',
    'cli_field_not_found' => "Field ':field' does not exist on target blueprint.",
    'cli_no_action_config' => "Field ':field' has no configured magic_actions_action.",
    'cli_multiple_actions' => "Field ':field' has multiple configured actions. Use --action= to select one.",
    'cli_action_missing' => "Configured action ':action' does not exist. Available actions: :available.",
    'cli_collection_not_found' => "Collection ':collection' not found. Available collections: :available.",
    'cli_entry_not_found' => 'Entry not found.',
    'cli_asset_not_found' => 'Asset not found. Expected format: container::path',

    // API error messages (ActionsController)
    'api_action_not_found' => 'Action not found',
    'api_context_required' => 'Context is required',
    'api_target_not_found' => "Context target not found for type ':type' and id ':id'.",
    'api_configure_key' => 'Please configure the required API key in the addon settings',
    'api_job_not_found' => 'Job not found',
    'api_batch_not_found' => 'Batch not found',
];
