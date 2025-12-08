import { isAssetValue } from './extractors'

export type EndpointType = 'completion' | 'vision' | 'transcription'

/**
 * Determines the action type based on field configuration and source value.
 */
export function determineActionType(promptType: string | undefined, sourceValue: unknown): EndpointType {
    if (promptType === 'audio') {
        return 'transcription'
    }

    if (promptType === 'text' && isAssetValue(sourceValue)) {
        return 'vision'
    }

    return 'completion'
}

/**
 * Applies the transformed value based on the mode (append or replace).
 */
export function applyTransformedValue(
    transformedData: unknown,
    currentValue: unknown,
    mode: 'append' | 'replace',
): unknown {
    if (mode === 'replace') {
        return transformedData
    }

    // Append mode
    if (Array.isArray(currentValue) && Array.isArray(transformedData)) {
        return [...currentValue, ...transformedData]
    }

    if (Array.isArray(transformedData)) {
        const existingArray = Array.isArray(currentValue) ? currentValue : []
        return [...existingArray, ...transformedData]
    }

    return transformedData
}
