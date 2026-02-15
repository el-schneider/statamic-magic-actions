import { executeCompletion, executeTranscription, executeVision } from './api'
import {
    determineActionType,
    extractPageContext,
    extractText,
    getAssetExtensionFromUrl,
    getAssetPath,
    isActionAllowedForExtension,
} from './helpers'
import magicIcon from './icons/magic.svg?raw'
import { recoverTrackedJobs, startBackgroundJob } from './job-tracker'
import type { ActionType, FieldActionConfig, FieldConfig, JobContext, MagicField, MagicFieldAction } from './types'

async function dispatchJob(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record<string, unknown>,
    pathname: string,
    context?: JobContext,
): Promise<string> {
    if (type === 'completion') {
        const sourceText = extractText(stateValues[config.magic_actions_source!])
        if (!sourceText) {
            throw new Error('Source field is empty')
        }

        const result = await executeCompletion(sourceText, action, context)
        return result.jobId
    }

    const assetPath = getAssetPath(config, stateValues, pathname)

    if (type === 'vision') {
        const result = await executeVision(assetPath, action, {}, context)
        return result.jobId
    }

    const result = await executeTranscription(assetPath, action, context)
    return result.jobId
}

function getConfiguredActions(config: FieldConfig): string[] {
    const configured = config.magic_actions_action

    if (typeof configured === 'string') {
        return configured ? [configured] : []
    }

    if (Array.isArray(configured)) {
        return configured.filter((actionHandle): actionHandle is string => typeof actionHandle === 'string')
    }

    return []
}

function createFieldAction(
    field: MagicField,
    action: MagicFieldAction,
    pageContext: JobContext | null,
): FieldActionConfig {
    return {
        title: action.title,
        quick: true,
        visible: ({ config, handle }) => {
            if (!config?.magic_actions_enabled || handle !== field.fieldHandle) {
                return false
            }

            if (!getConfiguredActions(config).includes(action.actionHandle)) {
                return false
            }

            return isActionAllowedForExtension(action.acceptedMimeTypes, getAssetExtensionFromUrl())
        },
        icon: action.icon ?? magicIcon,
        run: async ({ handle, update, store, storeName, config }) => {
            try {
                const stateValues = store.state.publish[storeName].values
                const pathname = window.location.pathname
                const actionType = determineActionType(action, config, stateValues, pathname)

                const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

                if (!fieldContext) {
                    throw new Error('Could not determine page context')
                }

                const jobId = await dispatchJob(
                    actionType,
                    action.actionHandle,
                    config,
                    stateValues,
                    pathname,
                    fieldContext,
                )

                window.Statamic.$toast.info(`"${action.title}" started...`)

                startBackgroundJob(fieldContext, jobId, handle, action.title, update)
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

        for (const action of field.actions) {
            window.Statamic.$fieldActions.add(componentName, createFieldAction(field, action, pageContext))
        }
    }

    if (pageContext) {
        recoverTrackedJobs(pageContext)
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
