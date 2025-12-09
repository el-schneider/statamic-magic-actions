import { executeCompletion, executeTranscription, executeVision } from './api'
import { determineActionType, extractPageContext, extractText, getAssetPath } from './helpers'
import magicIcon from './icons/magic.svg?raw'
import { recoverTrackedJobs, setActiveStore, startBackgroundJob } from './job-tracker'
import type { ActionType, FieldActionConfig, FieldConfig, JobContext, MagicField } from './types'

async function dispatchJob(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record,
    pathname: string,
    context?: JobContext,
): Promise {
    if (type === 'vision') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        const result = await executeVision(assetPath, action, {}, context)
        return result.jobId
    }

    if (type === 'transcription') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        const result = await executeTranscription(assetPath, action, context)
        return result.jobId
    }

    const sourceText = extractText(stateValues[config.magic_actions_source!])
    if (!sourceText) {
        throw new Error('Source field is empty')
    }
    const result = await executeCompletion(sourceText, action, context)
    return result.jobId
}

function createFieldAction(field: MagicField, pageContext: JobContext | null): FieldActionConfig {
    return {
        title: field.title,
        quick: true,
        visible: ({ config }) =>
            Boolean(config?.magic_actions_enabled && config?.magic_actions_action === field.action),
        icon: magicIcon,
        run: async ({ handle, store, storeName, config }) => {
            setActiveStore(store, storeName)

            try {
                const stateValues = store.state.publish[storeName].values
                const pathname = window.location.pathname
                const actionType = determineActionType(field, config, stateValues, pathname)

                const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

                if (!fieldContext) {
                    throw new Error('Could not determine page context')
                }

                const jobId = await dispatchJob(actionType, field.action, config, stateValues, pathname, fieldContext)

                window.Statamic.$toast.info(`"${field.title}" started...`)

                startBackgroundJob(fieldContext, jobId, handle, field.title)
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Failed to start the action'
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
        const store = window.Statamic?.Store?.store
        if (store) {
            setActiveStore(store, 'base')
        }
        recoverTrackedJobs(pageContext)
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
