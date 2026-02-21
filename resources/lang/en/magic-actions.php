<?php

declare(strict_types=1);

return [
    'nav' => [
        'tools' => 'Magic Actions',
    ],

    'bulk' => [
        'action_not_found' => 'Action not found.',
        'target_field' => [
            'display' => 'Target Field',
            'instructions' => 'Select the field that should receive the result.',
        ],
        'result' => [
            'queued' => 'Queued :action for :count item.|Queued :action for :count items.',
            'skipped' => ':count item was skipped.|:count items were skipped.',
            'failed_to_queue' => ':count item failed to queue.|:count items failed to queue.',
            'none_processed' => 'No items were processed.',
        ],
    ],

    'cli' => [
        'description' => 'Run magic actions against entries or assets from the CLI.',
        'options' => [
            'collection' => 'Target all entries in a collection',
            'entry' => 'Target a specific entry by ID',
            'asset' => 'Target a specific asset by container::path',
            'field' => 'Target field handle (required)',
            'action' => 'Override action handle',
            'overwrite' => 'Overwrite existing values',
            'no_overwrite' => 'Disable overwrite even when enabled by config',
            'queue' => 'Dispatch jobs to queue instead of running synchronously',
            'no_queue' => 'Run synchronously even when queueing is enabled by config',
            'dry_run' => 'Show what would be processed without making changes',
        ],
        'status' => [
            'failed' => 'failed',
            'skipped' => 'skipped',
            'queued' => 'queued',
            'processed' => 'processed',
        ],
        'messages' => [
            'dry_run' => 'Dry run: no changes will be made.',
            'dry_run_no_targets' => 'No targets would be processed.',
            'job_dispatched' => 'Job dispatched.',
            'completed_sync' => 'Completed synchronously.',
        ],
        'errors' => [
            'overwrite_conflict' => 'Use either --overwrite or --no-overwrite, not both.',
            'queue_conflict' => 'Use either --queue or --no-queue, not both.',
            'missing_field' => 'Missing required option: --field=',
            'missing_target' => 'Provide at least one target via --collection=, --entry=, or --asset=',
            'action_not_found' => "Action ':action' not found. Available actions: :actions. Check config/statamic/magic-actions.php.",
            'no_targets_resolved' => 'No targets resolved.',
            'could_not_determine_action' => 'Could not determine action.',
            'field_has_value' => 'Field already has a value. Use --overwrite to force.',
            'cannot_run_for_target' => 'Action cannot run for this target/field.',
            'collection_not_found' => "Collection ':collection' not found. Available collections: :collections.",
            'entry_not_found' => 'Entry not found.',
            'asset_not_found' => 'Asset not found. Expected format: container::path',
            'target_has_no_blueprint' => 'Target has no blueprint; unable to resolve field action config.',
            'field_missing_on_blueprint' => "Field ':field' does not exist on target blueprint.",
            'field_no_configured_actions' => "Field ':field' has no configured magic_actions_action.",
            'field_multiple_actions' => "Field ':field' has multiple configured actions. Use --action= to select one.",
            'configured_action_missing' => "Configured action ':action' does not exist. Available actions: :actions.",
        ],
        'table' => [
            'execution_headers' => [
                'target' => 'Target',
                'field' => 'Field',
                'action' => 'Action',
            ],
            'status_headers' => [
                'target' => 'Target',
                'field' => 'Field',
                'action' => 'Action',
                'status' => 'Status',
                'message' => 'Message',
            ],
            'summary_headers' => [
                'metric' => 'Metric',
                'count' => 'Count',
            ],
            'summary' => [
                'total_targets' => 'Total targets',
                'processed' => 'Processed',
                'skipped' => 'Skipped',
                'failed' => 'Failed',
                'dispatched_jobs' => 'Dispatched jobs',
                'batch_id' => 'Batch ID',
            ],
        ],
        'targets' => [
            'entry_label' => 'entry::id [collection=:collection, site=:site]',
            'asset_label' => 'asset::id',
        ],
        'misc' => [
            'none' => '(none)',
            'mixed' => 'mixed',
        ],
    ],

    'field_config' => [
        'section' => [
            'display' => 'Magic Actions',
        ],
        'enabled' => [
            'display' => 'Enabled',
        ],
        'source' => [
            'display' => 'Source',
            'instructions' => 'The field that contains the content to be processed by Magic Actions.',
            'placeholder' => 'content',
        ],
        'mode' => [
            'display' => 'Mode',
            'instructions' => 'Whether to append or replace to the existing content.',
            'options' => [
                'append' => 'Append',
                'replace' => 'Replace',
            ],
        ],
        'actions' => [
            'display' => 'Actions',
        ],
    ],

    'api' => [
        'errors' => [
            'job_not_found' => 'Job not found',
            'batch_not_found' => 'Batch not found',
            'action_not_found' => 'Action not found',
            'context_required' => 'Context is required',
            'context_target_not_found' => "Context target not found for type ':type' and id ':id'.",
        ],
        'messages' => [
            'configure_api_key' => 'Please configure the required API key in the addon settings',
            'settings_saved' => 'Settings saved',
        ],
    ],

    'settings' => [
        'global' => [
            'display' => 'Global Settings',
            'system_prompt' => [
                'display' => 'Global System Prompt',
                'instructions' => 'This prompt will be prepended to all action system prompts. Use it to describe your brand voice, style guidelines, or other global context.',
            ],
            'defaults' => [
                'display' => 'Default Models',
                'type_display' => ':type Model',
                'type_instructions' => 'Default model for :type actions.',
                'placeholder' => 'Use config default',
                'provider_model_label' => ':provider: :model',
                'instructions' => [
                    'base' => 'Default model to use for each capability when no action-specific override is set.',
                    'no_providers' => 'No providers configured. Add providers under `statamic.magic-actions.providers` to enable model selection.',
                    'no_api_keys' => '**No API keys configured.** Add provider API keys to your `.env` file (e.g. :env_keys) to enable model selection.',
                    'unlock_more' => 'To unlock more providers, add :env_keys to your `.env` file.',
                ],
            ],
        ],
    ],

    'errors' => [
        'missing_api_key_generic' => 'API key is not configured',
        'action_loader' => [
            'action_not_found' => "Action ':action' not found",
            'invalid_variables' => 'Invalid variables: :errors',
            'missing_model_configuration' => "Missing model configuration for type ':type'. Set statamic.magic-actions.types.:type.default.",
            'invalid_model_key_format' => "Invalid model key format: ':model'. Expected format: 'provider/model'",
            'missing_provider_api_key' => "API key not configured for provider ':provider'. Set :env in your .env file.",
        ],
        'action_executor' => [
            'execution_failed' => 'Action execution failed.',
            'action_not_found' => "Action ':action' not found. Check config/statamic/magic-actions.php and your field action configuration.",
            'cannot_execute_field' => "Action ':action' cannot be executed for field ':field'.",
            'unsupported_mime' => "Action :action does not support file type ':mime'. Accepted types: :accepted.",
            'unknown_mime' => 'unknown',
        ],
        'context_resolver' => [
            'invalid_resolver_variable' => "Invalid context resolver for variable ':variable'.",
            'unsupported_resolver' => "Unsupported context resolver ':resolver'.",
        ],
        'job' => [
            'queued' => 'Job has been queued',
            'processing' => 'Processing request...',
            'failed' => 'Job failed',
            'unknown_prompt_type' => 'Unknown prompt type: :type',
            'audio_asset_not_found' => 'Audio asset not found',
        ],
    ],

    'actions' => [
        'defaults' => [
            'bulk_confirmation' => ':title for this item?|:title for these :count items?',
            'bulk_button' => ':title|:title for :count Items',
        ],
        'alt-text' => [
            'title' => 'Alt Text',
            'bulk_confirmation' => 'Generate alt text for this asset?|Generate alt text for these :count assets?',
            'bulk_button' => 'Generate Alt Text|Generate Alt Text for :count Assets',
        ],
        'assign-tags-from-taxonomies' => [
            'title' => 'Assign Tags from Taxonomies',
            'bulk_confirmation' => 'Assign tags for this entry?|Assign tags for these :count entries?',
            'bulk_button' => 'Assign Tags|Assign Tags for :count Entries',
        ],
        'create-teaser' => [
            'title' => 'Create Teaser',
            'bulk_confirmation' => 'Create a teaser for this entry?|Create teasers for these :count entries?',
            'bulk_button' => 'Create Teaser|Create Teasers for :count Entries',
        ],
        'extract-assets-tags' => [
            'title' => 'Extract Tags',
            'bulk_confirmation' => 'Extract tags for this asset?|Extract tags for these :count assets?',
            'bulk_button' => 'Extract Tags|Extract Tags for :count Assets',
        ],
        'extract-meta-description' => [
            'title' => 'Extract Meta Description',
            'bulk_confirmation' => 'Generate a meta description for this entry?|Generate meta descriptions for these :count entries?',
            'bulk_button' => 'Generate Meta Description|Generate Meta Descriptions for :count Entries',
        ],
        'extract-tags' => [
            'title' => 'Extract Tags',
            'bulk_confirmation' => 'Extract tags for this entry?|Extract tags for these :count entries?',
            'bulk_button' => 'Extract Tags|Extract Tags for :count Entries',
        ],
        'image-caption' => [
            'title' => 'Image Caption',
            'bulk_confirmation' => 'Generate caption for this asset?|Generate captions for these :count assets?',
            'bulk_button' => 'Generate Caption|Generate Captions for :count Assets',
        ],
        'propose-title' => [
            'title' => 'Propose Title',
            'bulk_confirmation' => 'Propose a title for this entry?|Propose titles for these :count entries?',
            'bulk_button' => 'Propose Title|Propose Titles for :count Entries',
        ],
        'transcribe-audio' => [
            'title' => 'Transcribe Audio',
            'bulk_confirmation' => 'Transcribe this asset?|Transcribe these :count assets?',
            'bulk_button' => 'Transcribe Audio|Transcribe Audio for :count Assets',
        ],
    ],
];
