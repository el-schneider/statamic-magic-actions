/// <reference types="vite/client" />

import { extractAssetPathFromUrl, extractText, processApiResponse } from './extractors'
import { applyTransformedValue, determineActionType } from './helpers'
import { getTransformer } from './transformers'
import type { MagicField } from './types'

declare global {
    interface Window {
        StatamicConfig: {
            magicFields: MagicField[]
            providers: {
                openai?: {
                    api_key: string
                }
                google?: {
                    api_key: string
                }
            }
        }
        Statamic: {
            $fieldActions: {
                add: (type: string, config: FieldActionConfig) => void
            }
            $toast: {
                error: (message: string) => void
            }
            $axios: {
                get: (url: string) => Promise<{ data: unknown }>
                post: (url: string, data: unknown) => Promise<{ data: unknown }>
            }
            Store: {
                store: {
                    state: {
                        publish: Record<string, unknown>
                    }
                }
            }
        }
    }
}

interface FieldActionConfig {
    title: string
    quick: boolean
    visible: (context: { config: FieldConfig }) => boolean
    icon: string
    run: (context: FieldActionContext) => Promise<void>
}

interface FieldConfig {
    magic_actions_enabled?: boolean
    magic_actions_action?: string
    magic_actions_source?: string
    magic_actions_mode?: 'append' | 'replace'
}

interface FieldActionContext {
    handle: string
    value: unknown
    update: (value: unknown) => void
    store: {
        state: {
            publish: Record<string, { values: Record<string, unknown> }>
        }
    }
    storeName: string
    config: FieldConfig
}

const ENDPOINTS = {
    completion: '/!/statamic-magic-actions/completion',
    vision: '/!/statamic-magic-actions/vision',
    transcription: '/!/statamic-magic-actions/transcribe',
    status: '/!/statamic-magic-actions/status',
} as const

/**
 * Polls for job status until completed or failed.
 */
async function pollJobStatus(jobId: string, maxAttempts = 60, interval = 1000): Promise<unknown> {
    let attempts = 0

    while (attempts < maxAttempts) {
        const response = await window.Statamic.$axios.get(`${ENDPOINTS.status}/${jobId}`)
        const jobStatus = response.data as { status: string; data?: unknown; error?: string }

        if (jobStatus.status === 'completed') {
            return jobStatus.data
        }

        if (jobStatus.status === 'failed') {
            throw new Error(jobStatus.error || 'Job failed')
        }

        await new Promise((resolve) => setTimeout(resolve, interval))
        attempts++
    }

    throw new Error('Timed out waiting for job to complete')
}

/**
 * Execute a completion request.
 */
async function executeCompletion(text: string, action: string): Promise<unknown> {
    const response = await window.Statamic.$axios.post(ENDPOINTS.completion, { text, action })
    const data = response.data as { job_id?: string }

    if (!data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return pollJobStatus(data.job_id)
}

/**
 * Execute a vision request.
 */
async function executeVision(
    assetPath: string,
    action: string,
    variables: Record<string, string> = {},
): Promise<unknown> {
    const response = await window.Statamic.$axios.post(ENDPOINTS.vision, {
        asset_path: assetPath,
        action,
        variables,
    })
    const data = response.data as { job_id?: string }

    if (!data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return pollJobStatus(data.job_id)
}

/**
 * Execute a transcription request.
 */
async function executeTranscription(assetPath: string, action: string): Promise<unknown> {
    const response = await window.Statamic.$axios.post(ENDPOINTS.transcription, {
        asset_path: assetPath,
        action,
    })
    const data = response.data as { job_id?: string }

    if (!data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return pollJobStatus(data.job_id)
}

const MAGIC_ICON = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>`

/**
 * Creates the run handler for a field action.
 */
function createFieldActionHandler(field: MagicField) {
    return async ({ value, update, store, storeName, config }: FieldActionContext) => {
        try {
            const state = store.state.publish[storeName] as { values: Record<string, unknown> }
            const sourceFieldValue = state.values[config.magic_actions_source!]

            // Determine action type
            const actionType = determineActionType(field.promptType, sourceFieldValue)

            let apiResponse: unknown

            if (actionType === 'vision' || actionType === 'transcription') {
                // Extract asset path from URL or source field
                const assetPath =
                    extractAssetPathFromUrl(window.location.pathname) ||
                    (Array.isArray(sourceFieldValue) ? sourceFieldValue[0] : sourceFieldValue)

                if (!assetPath || typeof assetPath !== 'string') {
                    throw new Error('No asset selected')
                }

                if (actionType === 'vision') {
                    apiResponse = await executeVision(assetPath, field.action, {})
                } else {
                    apiResponse = await executeTranscription(assetPath, field.action)
                }
            } else {
                // Completion - extract text from source field
                const sourceText = extractText(sourceFieldValue)
                if (!sourceText) {
                    throw new Error('Source field is empty')
                }

                apiResponse = await executeCompletion(sourceText, field.action)
            }

            // Process the API response
            const processedResponse = processApiResponse(apiResponse)

            // Transform the data for the target field type
            const transformer = getTransformer(field.type)
            const mode = config.magic_actions_mode || 'append'
            const baseValue = mode === 'replace' ? (Array.isArray(value) ? [] : value) : value
            const transformedData = transformer(processedResponse, baseValue)

            // Apply the transformed value
            const newValue = applyTransformedValue(transformedData, value, mode)

            update(newValue)
        } catch (error) {
            console.error('Error in Magic Actions:', error)
            window.Statamic.$toast.error((error as Error).message || 'Failed to process the action')
        }
    }
}

/**
 * Register field actions for each configured magic field.
 */
function registerFieldActions() {
    const magicFields = window.StatamicConfig?.magicFields

    if (!magicFields || !Array.isArray(magicFields)) {
        return
    }

    magicFields.forEach((field) => {
        const componentName = `${field.component}-fieldtype`

        window.Statamic.$fieldActions.add(componentName, {
            title: field.title,
            quick: true,
            visible: ({ config }) =>
                Boolean(config?.magic_actions_enabled && config?.magic_actions_action === field.action),
            icon: MAGIC_ICON,
            run: createFieldActionHandler(field),
        })
    })
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
