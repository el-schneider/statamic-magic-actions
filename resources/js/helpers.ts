import type { ActionType, FieldConfig, JobContext, MagicFieldAction } from './types'

export function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

export function extractText(content: unknown): string {
    if (!content) return ''
    if (typeof content === 'string') return content

    if (typeof content === 'object' && content !== null) {
        const obj = content as Record<string, unknown>

        if (obj.type === 'text' && typeof obj.text === 'string') {
            return obj.text
        }

        if (Array.isArray(content)) {
            return content.map(extractText).filter(Boolean).join('\n')
        }

        return Object.values(obj).map(extractText).filter(Boolean).join('\n')
    }

    return ''
}

export function isAssetPath(value: unknown): boolean {
    if (typeof value === 'string') {
        return value.includes('::')
    }

    if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
        return value[0].includes('::')
    }

    return false
}

export function parseAssetPathFromUrl(pathname: string): string | null {
    const match = pathname.match(/browse\/([^/]+)\/(.+?)\/edit/)
    return match ? `${match[1]}::${match[2]}` : null
}

export function determineActionType(
    action: MagicFieldAction,
    config: FieldConfig,
    stateValues: Record<string, unknown>,
    pathname: string,
): ActionType {
    if (action.actionType === 'audio') {
        return 'transcription'
    }

    if (action.actionType === 'vision') {
        return 'vision'
    }

    const isAssetEditPage = parseAssetPathFromUrl(pathname) !== null

    if (isAssetEditPage && !config.magic_actions_source) {
        return 'vision'
    }

    const sourceValue = stateValues[config.magic_actions_source!]
    return isAssetPath(sourceValue) ? 'vision' : 'completion'
}

export function getAssetPath(config: FieldConfig, stateValues: Record<string, unknown>, pathname: string): string {
    const pathFromUrl = parseAssetPathFromUrl(pathname)
    if (pathFromUrl) {
        return pathFromUrl
    }

    const sourceValue = stateValues[config.magic_actions_source!]
    if (Array.isArray(sourceValue) && sourceValue.length > 0) {
        return sourceValue[0] as string
    }

    throw new Error('No asset selected')
}

export function extractPageContext(): JobContext | null {
    const url = window.location.pathname

    // Entry context: /cp/collections/{collection}/entries/{entryId}
    const entryMatch = url.match(/\/cp\/collections\/([^/]+)\/entries\/([^/]+)/)
    if (entryMatch) {
        return {
            type: 'entry',
            id: entryMatch[2],
            field: '',
        }
    }

    // Asset context: /cp/assets/browse/{container}/{path}/edit
    const assetMatch = url.match(/\/cp\/assets\/browse\/(.+?)\/edit/)
    if (assetMatch) {
        return {
            type: 'asset',
            id: assetMatch[1],
            field: '',
        }
    }

    return null
}
