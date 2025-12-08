import { executeCompletion, executeTranscription, executeVision } from './api'
import { applyUpdateMode, determineActionType, extractText, getAssetPath, wrapInBardBlock } from './helpers'
import magicIcon from './icons/magic.svg?raw'
import type { ActionType, FieldActionConfig, FieldConfig, MagicField } from './types'

async function executeAction(
    type: ActionType,
    action: string,
    config: FieldConfig,
    stateValues: Record,
    pathname: string,
): Promise {
    if (type === 'vision') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        return executeVision(assetPath, action)
    }

    if (type === 'transcription') {
        const assetPath = getAssetPath(config, stateValues, pathname)
        return executeTranscription(assetPath, action)
    }

    const sourceText = extractText(stateValues[config.magic_actions_source!])
    if (!sourceText) {
        throw new Error('Source field is empty')
    }

    return executeCompletion(sourceText, action)
}

function createFieldAction(field: MagicField): FieldActionConfig {
    return {
        title: field.title,
        quick: true,
        visible: ({ config }) =>
            Boolean(config?.magic_actions_enabled && config?.magic_actions_action === field.action),
        icon: magicIcon,
        run: async ({ value, update, store, storeName, config }) => {
            try {
                const stateValues = store.state.publish[storeName].values
                const pathname = window.location.pathname
                const actionType = determineActionType(field, config, stateValues, pathname)

                const result = await executeAction(actionType, field.action, config, stateValues, pathname)

                const formattedResult = field.type === 'bard' ? wrapInBardBlock(result) : result
                const mode = config.magic_actions_mode || 'replace'
                const newValue = applyUpdateMode(value, formattedResult, mode)

                update(newValue)
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

    for (const field of magicFields) {
        const componentName = `${field.component}-fieldtype`
        window.Statamic.$fieldActions.add(componentName, createFieldAction(field))
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}

export default {}
