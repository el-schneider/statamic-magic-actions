import type { ActionType, FieldConfig, JobContext, MagicFieldAction } from './types'

const EXT_TO_MIME: Record<string, string> = {
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    gif: 'image/gif',
    webp: 'image/webp',
    svg: 'image/svg+xml',
    mp3: 'audio/mpeg',
    mp4: 'audio/mp4',
    wav: 'audio/wav',
    webm: 'audio/webm',
    ogg: 'audio/ogg',
    flac: 'audio/flac',
    pdf: 'application/pdf',
}

function getCpRoot(): string {
    const cpRootFromConfig =
        window.Statamic?.$config && typeof window.Statamic.$config.get === 'function'
            ? window.Statamic.$config.get('cpRoot', '/cp')
            : window.Statamic?.$config?.cpRoot

    return String(cpRootFromConfig ?? '/cp').replace(/\/+$/, '')
}

function escapeForRegex(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

export function extractText(content: unknown): string {
    if (!content) {
        return ''
    }

    if (typeof content === 'string') {
        return content
    }

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

    if (Array.isArray(value)) {
        const firstString = value.find((item): item is string => typeof item === 'string')

        return typeof firstString === 'string' && firstString.includes('::')
    }

    return false
}

export function parseAssetPathFromUrl(pathname: string): string | null {
    const cpRootPattern = escapeForRegex(getCpRoot())
    const match = pathname.match(new RegExp(`^${cpRootPattern}/assets/browse/([^/]+)/(.+?)/edit$`))

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

    const sourceValue = config.magic_actions_source ? stateValues[config.magic_actions_source] : undefined

    return isAssetPath(sourceValue) ? 'vision' : 'completion'
}

export function getAssetPath(config: FieldConfig, stateValues: Record<string, unknown>, pathname: string): string {
    const pathFromUrl = parseAssetPathFromUrl(pathname)
    if (pathFromUrl) {
        return pathFromUrl
    }

    const sourceValue = config.magic_actions_source ? stateValues[config.magic_actions_source] : undefined
    if (Array.isArray(sourceValue) && sourceValue.length > 0) {
        return sourceValue[0] as string
    }

    throw new Error('No asset selected')
}

export function extractPageContext(): JobContext | null {
    const url = window.location.pathname
    const cpRootPattern = escapeForRegex(getCpRoot())

    const entryMatch = url.match(new RegExp(`^${cpRootPattern}/collections/[^/]+/entries/([^/]+)`))
    const entryId = entryMatch?.[1]
    if (entryId) {
        return {
            type: 'entry',
            id: entryId,
            field: '',
        }
    }

    const assetMatch = url.match(new RegExp(`^${cpRootPattern}/assets/browse/(.+?)/edit`))
    const assetId = assetMatch?.[1]
    if (assetId) {
        return {
            type: 'asset',
            id: assetId,
            field: '',
        }
    }

    return null
}

export function getAssetExtensionFromUrl(): string | null {
    const cpRootPattern = escapeForRegex(getCpRoot())
    const match = window.location.pathname.match(new RegExp(`^${cpRootPattern}/assets/browse/[^/]+/(.+?)/edit$`))
    if (!match) {
        return null
    }

    const matchedPath = match[1]
    if (!matchedPath) {
        return null
    }

    const path = decodeURIComponent(matchedPath)
    const fileName = path.split('/').pop() ?? path
    const dotIndex = fileName.lastIndexOf('.')

    if (dotIndex <= 0 || dotIndex === fileName.length - 1) {
        return null
    }

    return fileName.substring(dotIndex + 1).toLowerCase()
}

export function isActionAllowedForExtension(acceptedMimeTypes: string[], extension: string | null): boolean {
    if (acceptedMimeTypes.length === 0) {
        return true
    }

    if (!extension) {
        return true
    }

    const mimeType = EXT_TO_MIME[extension]
    if (!mimeType) {
        return true
    }

    const normalizedMimeType = mimeType.toLowerCase()

    return acceptedMimeTypes.some((acceptedMimeType) => {
        const normalizedAcceptedMimeType = acceptedMimeType.toLowerCase()

        if (normalizedAcceptedMimeType === '*/*') {
            return true
        }

        if (normalizedAcceptedMimeType.endsWith('/*')) {
            return normalizedMimeType.startsWith(normalizedAcceptedMimeType.slice(0, -1))
        }

        return normalizedAcceptedMimeType === normalizedMimeType
    })
}
