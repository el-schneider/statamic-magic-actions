import type { ApiResponse } from './types'

/**
 * Recursively extracts text content from various data structures.
 * Handles Bard content, arrays, and nested objects.
 */
export function extractText(content: unknown): string | null | undefined {
    // Return null/undefined as-is
    if (content === null || content === undefined) {
        return content
    }

    // Return strings directly
    if (typeof content === 'string') {
        return content
    }

    // Handle objects with type: 'text' and text property
    if (
        typeof content === 'object' &&
        'type' in content &&
        (content as { type: string }).type === 'text' &&
        'text' in content
    ) {
        return (content as { text: string }).text
    }

    // Handle arrays - recursively extract and join with newlines
    if (Array.isArray(content)) {
        return content
            .map(extractText)
            .filter((t): t is string => Boolean(t))
            .join('\n')
    }

    // Handle other objects - recursively extract from values (skip 'type' keys)
    if (typeof content === 'object') {
        return Object.entries(content)
            .filter(([key]) => key !== 'type')
            .map(([, value]) => extractText(value))
            .filter((t): t is string => Boolean(t))
            .join('\n')
    }

    return String(content)
}

/**
 * Processes API response data to extract the actual content.
 * Handles various response formats from the backend.
 */
export function processApiResponse(response: unknown): unknown {
    // Return null/undefined as-is
    if (response === null || response === undefined) {
        return response
    }

    // Return non-objects as-is
    if (typeof response !== 'object') {
        return response
    }

    const resp = response as ApiResponse

    // Handle direct string in data property (from job response)
    if ('data' in resp && typeof resp.data === 'string') {
        return resp.data
    }

    // Handle content property
    if ('content' in resp && resp.content) {
        // Try to parse JSON content if it exists
        try {
            const jsonMatch = resp.content.match(/(\[[\s\S]*\]|\{[\s\S]*\})/)?.[0]
            if (jsonMatch) {
                return JSON.parse(jsonMatch)
            }
        } catch {
            // If not JSON, return wrapped
        }

        return { data: resp.content }
    }

    // Handle text property
    if ('text' in resp && resp.text) {
        return { data: resp.text }
    }

    return response
}

/**
 * Extracts asset path from a Statamic asset edit URL.
 * Converts URL format to container::filename format.
 *
 * @param url - The current page URL (e.g., /cp/assets/browse/images/photo.jpg/edit)
 * @returns Asset path in format "container::filename" or null if not an asset URL
 */
export function extractAssetPathFromUrl(url: string): string | null {
    const match = url.match(/browse\/([^/]+)\/(.+?)\/edit/)
    if (match) {
        // match[1] = container, match[2] = filename
        return `${match[1]}::${match[2]}`
    }
    return null
}

/**
 * Determines if a source value represents an asset field.
 * Asset paths contain "::" (e.g., "images::photo.jpg")
 */
export function isAssetValue(value: unknown): boolean {
    if (typeof value === 'string' && value.includes('::')) {
        return true
    }
    if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string' && value[0].includes('::')) {
        return true
    }
    return false
}

/**
 * Gets the first asset path from a value that may be a string or array.
 */
export function getFirstAssetPath(value: unknown): string | undefined {
    if (typeof value === 'string') {
        return value
    }
    if (Array.isArray(value) && value.length > 0) {
        return value[0]
    }
    return undefined
}
