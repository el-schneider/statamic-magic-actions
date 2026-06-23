<?php

declare(strict_types=1);

return [
    // Field config (FieldConfigService)
    'field_config' => [
        'section' => 'Magic Actions',
        'enabled' => 'Enabled',
        'source' => 'Source',
        'source_instructions' => 'The field that contains the content to be processed by Magic Actions.',
        'source_placeholder' => 'content',
        'mode' => 'Mode',
        'mode_instructions' => 'Whether to append or replace to the existing content.',
        'mode_append' => 'Append',
        'mode_replace' => 'Replace',
        'actions' => 'Actions',
    ],

    // Settings blueprint
    'settings' => [
        'global' => 'Global Settings',
        'system_prompt' => 'Global System Prompt',
        'system_prompt_instructions' => 'This prompt will be prepended to all action system prompts. Use it to describe your brand voice, style guidelines, or other global context.',
        'default_models' => 'Default Models',
        'default_models_base' => 'Default model to use for each capability when no action-specific override is set.',
        'default_models_no_providers' => 'No providers configured. Add providers under `statamic.magic-actions.providers` to enable model selection.',
        'default_models_no_keys' => '**No API keys configured.** Add provider API keys to your `.env` file (e.g. :keys) to enable model selection.',
        'default_models_unlock' => 'To unlock more providers, add :keys to your `.env` file.',
        'model_field_instructions' => 'Default model for :type actions.',
        'model_field_placeholder' => 'Use config default',
    ],

    // Bulk actions (DynamicBulkAction)
    'bulk' => [
        'target_field' => 'Target Field',
        'target_field_instructions' => 'Select the field that should receive the result.',
        'queued' => 'Queued :title for :count item.|Queued :title for :count items.',
        'skipped' => ':count item was skipped.|:count items were skipped.',
        'failed' => ':count item failed to queue.|:count items failed to queue.',
        'no_items' => 'No items were processed.',
        'confirm' => ':title for this item?|:title for these :count items?',
        'button' => ':title|:title for :count Items',
    ],

    // CLI command (MagicRunCommand)
    'cli' => [
        'overwrite_conflict' => 'Use either --overwrite or --no-overwrite, not both.',
        'queue_conflict' => 'Use either --queue or --no-queue, not both.',
        'missing_field' => 'Missing required option: --field=',
        'missing_target' => 'Provide at least one target via --collection=, --entry=, or --asset=',
        'action_not_found' => "Action ':action' not found. Available actions: :available.",
        'action_not_found_hint' => 'Use --action=<handle> with one of the above.',
        'no_targets' => 'No targets resolved.',
        'dry_run' => 'Dry run: no changes will be made.',
        'dry_run_empty' => 'No targets would be processed.',
        'collection_not_found' => "Collection ':collection' not found. Available collections: :available.",
        'field_not_found' => "Field ':field' does not exist on target blueprint.",
        'field_no_action' => "Field ':field' has no configured magic_actions_action.",
        'field_multiple_actions' => "Field ':field' has multiple configured actions. Use --action= to select one.",
        'configured_action_not_found' => "Configured action ':action' does not exist. Available actions: :available.",
    ],

    // Error messages (ActionExecutor, ActionLoader)
    'errors' => [
        'action_not_found' => "Action ':action' not found.",
        'action_field_mismatch' => "Action ':action' cannot be executed for field ':field'.",
        'unsupported_file_type' => "Action :action does not support file type ':type'. Accepted types: :accepted.",
        'invalid_model_key' => "Invalid model key format: ':key'. Expected format: 'provider/model'.",
        'invalid_context_variable' => "Invalid context resolver for variable ':variable'.",
        'unsupported_resolver' => "Unsupported context resolver ':resolver'.",
        'unknown_prompt_type' => 'Unknown prompt type: :type',
        'context_not_found' => "Context target not found for type ':type' and id ':id'.",
    ],
];
