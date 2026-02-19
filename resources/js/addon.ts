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
    FieldActionVm,
    FieldConfig,
    FieldMeta,
    JobContext,
    MagicActionCatalog,
    MagicFieldAction,
    RelationshipMetaSyncContext,
} from './types'

const registeredFieldActions = new Set<string>()
let hasRecoveredTrackedJobs = false

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

function getRelationshipMetaSyncContext(
    store: Window['Statamic']['Store']['store'],
    storeName: string,
    meta: FieldMeta | null | undefined,
    updateMeta: (value: FieldMeta) => void,
    vm: FieldActionVm | null | undefined,
): RelationshipMetaSyncContext | undefined {
    const metaItemDataUrl = typeof meta?.itemDataUrl === 'string' ? meta.itemDataUrl : undefined
    const vmItemDataUrl = typeof vm?.itemDataUrl === 'string' ? vm.itemDataUrl : undefined
    const itemDataUrl = vmItemDataUrl ?? metaItemDataUrl

    if (!itemDataUrl) {
        return undefined
    }

    const site = vm?.site ?? store.state.publish[storeName]?.site ?? window.Statamic.$config.get('selectedSite')

    return {
        meta: meta ?? {},
        updateMeta,
        itemDataUrl,
        site,
        vm: vm ?? undefined,
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
        run: async ({ handle, update, updateMeta, meta, vm, store, storeName, config }) => {
            try {
                const publishState = store.state.publish[storeName]
                if (!publishState) {
                    throw new Error('Could not determine publish state')
                }

                const stateValues = publishState.values
                const pathname = window.location.pathname
                const actionType = determineActionType(action, config, stateValues, pathname)
                const pageContext = extractPageContext()

                const fieldContext: JobContext | undefined = pageContext ? { ...pageContext, field: handle } : undefined

                if (!fieldContext) {
                    throw new Error('Could not determine page context')
                }

                const jobId = await dispatchJob(actionType, action.handle, config, stateValues, pathname, fieldContext)

                window.Statamic.$toast.info(`"${action.title}" started...`)

                const relationshipMetaSync = getRelationshipMetaSyncContext(store, storeName, meta, updateMeta, vm)
                const boundUpdate = vm ? update.bind(vm) : update

                startBackgroundJob(fieldContext, jobId, handle, action.title, boundUpdate, relationshipMetaSync)
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Failed to start the action'
                window.Statamic.$toast.error(message)
            }
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
