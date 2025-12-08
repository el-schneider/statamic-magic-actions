import { describe, expect, it } from 'vitest'
import { applyTransformedValue, determineActionType } from './helpers'

describe('determineActionType', () => {
    it('returns transcription for audio promptType', () => {
        expect(determineActionType('audio', null)).toBe('transcription')
        expect(determineActionType('audio', 'some value')).toBe('transcription')
    })

    it('returns vision for text promptType with asset value (string with ::)', () => {
        expect(determineActionType('text', 'images::photo.jpg')).toBe('vision')
    })

    it('returns vision for text promptType with asset array', () => {
        expect(determineActionType('text', ['images::photo.jpg', 'images::other.jpg'])).toBe('vision')
    })

    it('returns completion for text promptType with non-asset value', () => {
        expect(determineActionType('text', 'regular text')).toBe('completion')
        expect(determineActionType('text', ['regular', 'array'])).toBe('completion')
    })

    it('returns completion when promptType is undefined', () => {
        expect(determineActionType(undefined, 'some value')).toBe('completion')
    })

    it('returns completion for unknown promptType', () => {
        expect(determineActionType('unknown', 'some value')).toBe('completion')
    })
})

describe('applyTransformedValue', () => {
    describe('replace mode', () => {
        it('returns transformed data directly', () => {
            expect(applyTransformedValue('new value', 'old value', 'replace')).toBe('new value')
        })

        it('returns transformed array directly', () => {
            const transformed = ['new1', 'new2']
            expect(applyTransformedValue(transformed, ['old1'], 'replace')).toEqual(transformed)
        })
    })

    describe('append mode', () => {
        it('concatenates two arrays', () => {
            const current = ['existing1', 'existing2']
            const transformed = ['new1', 'new2']
            expect(applyTransformedValue(transformed, current, 'append')).toEqual([
                'existing1',
                'existing2',
                'new1',
                'new2',
            ])
        })

        it('appends array to empty array', () => {
            const transformed = ['new1', 'new2']
            expect(applyTransformedValue(transformed, [], 'append')).toEqual(['new1', 'new2'])
        })

        it('creates array from non-array current value when transformed is array', () => {
            const transformed = ['new1', 'new2']
            expect(applyTransformedValue(transformed, 'not an array', 'append')).toEqual(['new1', 'new2'])
        })

        it('returns non-array transformed data as-is', () => {
            expect(applyTransformedValue('new text', 'old text', 'append')).toBe('new text')
        })

        it('handles null current value with array transformed', () => {
            const transformed = ['new1']
            expect(applyTransformedValue(transformed, null, 'append')).toEqual(['new1'])
        })
    })
})
