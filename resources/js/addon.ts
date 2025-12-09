import { acknowledgeJob, executeCompletion, executeTranscription, executeVision, pollJobStatus } from './api'
import {
    applyUpdateMode,
    determineActionType,
    extractPageContext,
    extractText,
    getAssetPath,
    wrapInBardBlock,
} from './helpers'
import magicIcon from './icons/magic.svg?raw'
import { recoverBackgroundJobs } from './job-recovery'
import { removeTrackedJob, trackJob } from './job-storage'
import type { ActionType, FieldActionConfig, FieldConfig, JobContext, MagicField } from './types'

async function executeAction(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record,
    pathname: string,
    context?: JobContext,
): Promise {
    let jobResult: { jobId: string; context?: JobContext }

    if (type === 'vision') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        jobResult = await executeVision(assetPath, action, {}, context)
    } else if (type === 'transcription') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        jobResult = await executeTranscription(assetPath, action, context)
    } else {
        const sourceText = extractText(stateValues[config.magic_actions_source!])
        if (!sourceText) {
            throw new Error('Source field is empty')
        }
        jobResult = await executeCompletion(sourceText, action, context)
    }

    const status = await pollJobStatus(jobResult.jobId)

    if (jobResult.context) {
        await acknowledgeJob(jobResult.jobId)
    }

    return { jobId: jobResult.jobId, data: status.data! }
}

function createFieldAction(field: MagicField, pageContext: JobContext | null): FieldActionConfig {
    return {
        title: field.title,
        quick: true,
        visible: ({ config }) =>
            Boolean(config?.magic_actions_enabled && config?.magic_actions_action === field.action),
        icon: magicIcon,
        run: async ({ handle, value, update, store, storeName, config }) => {
            try {
                const stateValues = store.state.publish[storeName].values
                const pathname = window.location.pathname
                const actionType = determineActionType(field, config, stateValues, pathname)

                const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

                if (fieldContext) {
                    trackJob({
                        jobId: '', // Will be updated after job starts
                        action: field.action,
                        fieldHandle: handle,
                        fieldType: field.type,
                        startedAt: new Date().toISOString(),
                        context: fieldContext,
                    })
                }

                window.Statamic.$toast.info('Magic action started. You can navigate away safely.')

                const { jobId, data } = await executeAction(
                    actionType,
                    field.action,
                    config,
                    stateValues,
                    pathname,
                    fieldContext,
                )

                if (fieldContext) {
                    removeTrackedJob(fieldContext, jobId)
                }

                const formattedResult = field.type === 'bard' ? wrapInBardBlock(data) : data
                const mode = config.magic_actions_mode || 'replace'
                const newValue = applyUpdateMode(value, formattedResult, mode)

                update(newValue)
                window.Statamic.$toast.success('Magic action completed!')
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Failed to process the action'
                window.Statamic.$toast.error(message)
            }
        },
    }
}

function registerFieldActions(): void {
    const magicFields = window.StatamicConfig?.magicFields
    if (!magicFields?.length) {
        return
    }

    const pageContext = extractPageContext()

    for (const field of magicFields) {
        const componentName = `${field.component}-fieldtype`
        window.Statamic.$fieldActions.add(componentName, createFieldAction(field, pageContext))
    }

    if (pageContext) {
        setTimeout(() => {
            try {
                const store = window.Statamic.Store.store
                const storeNames = Object.keys(store.state.publish || {})
                if (storeNames.length > 0) {
                    recoverBackgroundJobs(pageContext, store, storeNames[0], magicFields)
                }
            } catch (error) {
                console.error('Error recovering background jobs:', error)
            }
        }, 1000)
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
