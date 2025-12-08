import type { BardNode } from './types'

/**
 * Helper to extract the actual content from API response data.
 * Handles wrapped { data: "..." } format from the backend.
 */
function unwrapData(data: unknown): unknown {
    if (data && typeof data === 'object' && 'data' in data) {
        const obj = data as { data: unknown }
        return obj.data
    }
    return data
}

/**
 * Transforms API response data to a plain text string.
 */
function textTransformer(data: unknown): string {
    const content = unwrapData(data)
    if (typeof content === 'string') {
        return content
    }
    return String(content)
}

/**
 * Parses a string into an array of tags.
 * Supports both quoted CSV format ("tag1", "tag2") and plain comma-separated.
 */
function parseTagString(content: string): string[] {
    // Parse quoted CSV format: "tag1", "tag2", "tag3"
    const matches = content.match(/"([^"]*)"/g)
    if (matches) {
        return matches.map((m) => m.replace(/"/g, ''))
    }
    // Fallback to comma-separated
    return content.split(',').map((t) => t.trim())
}

/**
 * Transforms API response data to an array of tags.
 * Limits output to 10 items.
 */
function tagsTransformer(data: unknown): unknown[] {
    const content = unwrapData(data)

    if (Array.isArray(content)) {
        return content.slice(0, 10)
    }

    if (typeof content === 'string') {
        return parseTagString(content).slice(0, 10)
    }

    return [content]
}

/**
 * Transforms API response data to an array of terms.
 * Identical to tags transformer - limits output to 10 items.
 */
function termsTransformer(data: unknown): unknown[] {
    return tagsTransformer(data)
}

/**
 * Transforms API response data to a Bard paragraph node.
 * Appends to the existing content array.
 */
function bardTransformer(data: unknown, currentValue: BardNode[] = []): BardNode[] {
    const text = textTransformer(data)
    return [
        ...currentValue,
        {
            type: 'paragraph',
            content: [
                {
                    type: 'text',
                    text,
                },
            ],
        },
    ]
}

/**
 * Transforms API response data for assets.
 * Returns the data as-is (could be alt text, tags, etc.)
 */
function assetsTransformer(data: unknown): unknown {
    const content = unwrapData(data)
    return content
}

/**
 * Map of field types to their transformer functions.
 */
export const transformers = {
    text: textTransformer,
    tags: tagsTransformer,
    terms: termsTransformer,
    bard: bardTransformer,
    assets: assetsTransformer,
}

/**
 * Get the appropriate transformer for a field type.
 * Falls back to text transformer if type is unknown.
 */
export function getTransformer(fieldType: string): (data: unknown, currentValue?: unknown) => unknown {
    return transformers[fieldType as keyof typeof transformers] || transformers.text
}
