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
import type {
    ActionType,
    FieldActionConfig,
    FieldConfig,
    JobContext,
    MagicActionCatalog,
    MagicFieldAction,
    RunContext,
} from './types'

const registeredFieldActions = new Set<string>()
let hasRecoveredTrackedJobs = false

type UnknownRecord = Record<string, unknown>

function isRecord(value: unknown): value is UnknownRecord {
    return typeof value === 'object' && value !== null
}

function unwrapRefLike(value: unknown): unknown {
    if (!isRecord(value)) {
        return value
    }

    return 'value' in value ? value.value : value
}

function extractValuesObject(value: unknown): Record<string, unknown> | null {
    const unwrapped = unwrapRefLike(value)

    return isRecord(unwrapped) ? unwrapped : null
}

function extractValuesFromContainer(container: unknown): Record<string, unknown> | null {
    if (!isRecord(container)) {
        return null
    }

    return extractValuesObject(container.values)
}

function extractValuesFromPublishMap(
    publishMap: unknown,
    storeName: string | undefined,
): Record<string, unknown> | null {
    if (!isRecord(publishMap) || typeof storeName !== 'string' || storeName.length === 0) {
        return null
    }

    if (!(storeName in publishMap)) {
        return null
    }

    return extractValuesFromContainer(publishMap[storeName])
}

function resolveStateValues(context: RunContext): Record<string, unknown> {
    const store = context.store

    if (isRecord(store)) {
        const storeState = isRecord(store.state) ? store.state : null

        const legacyValues = extractValuesFromPublishMap(storeState?.publish, context.storeName)
        if (legacyValues) {
            return legacyValues
        }

        const piniaValues = extractValuesFromPublishMap(store.publish, context.storeName)
        if (piniaValues) {
            return piniaValues
        }

        if (typeof context.storeName === 'string') {
            const valuesByStoreName = extractValuesFromContainer(store[context.storeName])
            if (valuesByStoreName) {
                return valuesByStoreName
            }
        }

        const directStoreValues = extractValuesFromContainer(store)
        if (directStoreValues) {
            return directStoreValues
        }
    }

    const directContainerValues = extractValuesFromContainer(context.publishContainer)
    if (directContainerValues) {
        return directContainerValues
    }

    if (isRecord(context.vm)) {
        const vmPublishContainerValues = extractValuesFromContainer(context.vm.publishContainer)
        if (vmPublishContainerValues) {
            return vmPublishContainerValues
        }

        const injectedContainerValues = extractValuesFromContainer(context.vm.injectedPublishContainer)
        if (injectedContainerValues) {
            return injectedContainerValues
        }
    }

    return {
        [context.handle]: context.value,
    }
}

async function dispatchJob(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record<string, unknown>,
    pathname: string,
    context?: JobContext,
): Promise<string> {
    if (type === 'completion') {
        const sourceFieldHandle = config.magic_actions_source
        const sourceText = sourceFieldHandle
            ? extractText(stateValues[sourceFieldHandle])
            : extractText(stateValues[context?.field ?? ''])

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

async function runFieldAction(action: MagicFieldAction, runContext: RunContext): Promise<void> {
    const { handle, update, config } = runContext

    try {
        const stateValues = resolveStateValues(runContext)
        const pathname = window.location.pathname
        const actionType = determineActionType(action, config, stateValues, pathname)
        const pageContext = extractPageContext()

        const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

        if (!fieldContext) {
            throw new Error('Could not determine page context')
        }

        const jobId = await dispatchJob(actionType, action.handle, config, stateValues, pathname, fieldContext)

        window.Statamic.$toast.info(`"${action.title}" started...`)

        startBackgroundJob(fieldContext, jobId, handle, action.title, update)
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Failed to start the action'
        window.Statamic.$toast.error(message)
    }
}

function createFieldAction(action: MagicFieldAction): FieldActionConfig {
    return {
        title: action.title,
        quick: true,
        visible: ({ config }) => {
            if (!config?.magic_actions_enabled) {
                return false
            }

            if (!getConfiguredActions(config).includes(action.handle)) {
                return false
            }

            return isActionAllowedForExtension(action.acceptedMimeTypes, getAssetExtensionFromUrl())
        },
        icon: action.icon ?? magicIcon,
        run: (runContext) => {
            void runFieldAction(action, runContext)
        },
    }
}

function registerFieldActions(): void {
    const magicActionCatalog: MagicActionCatalog = window.StatamicConfig?.magicActionCatalog ?? {}

    for (const [component, actions] of Object.entries(magicActionCatalog)) {
        const componentName = `${component}-fieldtype`

        for (const action of actions) {
            const registrationKey = `${component}:${action.handle}`
            if (registeredFieldActions.has(registrationKey)) {
                continue
            }

            window.Statamic.$fieldActions.add(componentName, createFieldAction(action))
            registeredFieldActions.add(registrationKey)
        }
    }

    if (!hasRecoveredTrackedJobs) {
        const pageContext = extractPageContext()
        if (pageContext) {
            recoverTrackedJobs(pageContext)
            hasRecoveredTrackedJobs = true
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
