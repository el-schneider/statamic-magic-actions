import { executeCompletion, executeTranscription, executeVision, pollJobStatus } from './api'
import { determineActionType, extractPageContext, extractText, getAssetPath } from './helpers'
import magicIcon from './icons/magic.svg?raw'
import type { ActionType, FieldActionConfig, FieldConfig, JobContext, MagicField } from './types'

async function executeAction(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record,
    pathname: string,
    context?: JobContext,
): Promise {
    let jobId: string

    if (type === 'vision') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        const result = await executeVision(assetPath, action, {}, context)
        jobId = result.jobId
    } else if (type === 'transcription') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        const result = await executeTranscription(assetPath, action, context)
        jobId = result.jobId
    } else {
        const sourceText = extractText(stateValues[config.magic_actions_source!])
        if (!sourceText) {
            throw new Error('Source field is empty')
        }
        const result = await executeCompletion(sourceText, action, context)
        jobId = result.jobId
    }

    await pollJobStatus(jobId)
    return jobId
}

function createFieldAction(field: MagicField, pageContext: JobContext | null): FieldActionConfig {
    return {
        title: field.title,
        quick: true,
        visible: ({ config }) =>
            Boolean(config?.magic_actions_enabled && config?.magic_actions_action === field.action),
        icon: magicIcon,
        run: async ({ handle, store, storeName, config }) => {
            try {
                const stateValues = store.state.publish[storeName].values
                const pathname = window.location.pathname
                const actionType = determineActionType(field, config, stateValues, pathname)

                const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

                window.Statamic.$toast.info('Magic action started...')

                await executeAction(actionType, field.action, config, stateValues, pathname, fieldContext)

                window.Statamic.$toast.success('Magic action completed! Refresh the page to see changes.')
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
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
