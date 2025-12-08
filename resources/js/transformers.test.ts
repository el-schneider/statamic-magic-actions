import { describe, expect, it } from 'vitest'
import { transformers } from './transformers'

describe('transformers', () => {
    describe('text', () => {
        it('extracts string from data.data property', () => {
            const input = { data: 'Hello World' }
            expect(transformers.text(input)).toBe('Hello World')
        })

        it('returns string directly when input is a string', () => {
            expect(transformers.text('Hello World')).toBe('Hello World')
        })

        it('converts non-string values to string', () => {
            expect(transformers.text(123)).toBe('123')
            expect(transformers.text(true)).toBe('true')
        })

        it('handles null and undefined', () => {
            expect(transformers.text(null)).toBe('null')
            expect(transformers.text(undefined)).toBe('undefined')
        })

        it('handles nested data object with non-string data property', () => {
            const input = { data: { nested: 'value' } }
            expect(transformers.text(input)).toBe('[object Object]')
        })
    })

    describe('tags', () => {
        it('extracts tags from quoted CSV format', () => {
            const input = { data: '"tag1", "tag2", "tag3"' }
            expect(transformers.tags(input)).toEqual(['tag1', 'tag2', 'tag3'])
        })

        it('extracts tags from comma-separated string', () => {
            const input = { data: 'tag1, tag2, tag3' }
            expect(transformers.tags(input)).toEqual(['tag1', 'tag2', 'tag3'])
        })

        it('extracts tags from string directly', () => {
            expect(transformers.tags('"apple", "banana", "cherry"')).toEqual(['apple', 'banana', 'cherry'])
        })

        it('handles array input', () => {
            const input = ['tag1', 'tag2', 'tag3']
            expect(transformers.tags(input)).toEqual(['tag1', 'tag2', 'tag3'])
        })

        it('limits output to 10 tags', () => {
            const input = {
                data: '"a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l"',
            }
            expect(transformers.tags(input)).toHaveLength(10)
        })

        it('wraps single non-array value in array', () => {
            expect(transformers.tags(123)).toEqual([123])
        })

        it('handles data.data with array content', () => {
            const input = { data: ['tag1', 'tag2'] }
            expect(transformers.tags(input)).toEqual(['tag1', 'tag2'])
        })
    })

    describe('terms', () => {
        it('extracts terms from quoted CSV format', () => {
            const input = { data: '"term1", "term2", "term3"' }
            expect(transformers.terms(input)).toEqual(['term1', 'term2', 'term3'])
        })

        it('extracts terms from comma-separated string', () => {
            const input = { data: 'term1, term2, term3' }
            expect(transformers.terms(input)).toEqual(['term1', 'term2', 'term3'])
        })

        it('handles array input', () => {
            const input = ['term1', 'term2', 'term3']
            expect(transformers.terms(input)).toEqual(['term1', 'term2', 'term3'])
        })

        it('limits output to 10 terms', () => {
            const input = {
                data: '"a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l"',
            }
            expect(transformers.terms(input)).toHaveLength(10)
        })
    })

    describe('bard', () => {
        it('appends paragraph to existing bard content', () => {
            const currentValue = [{ type: 'paragraph', content: [{ type: 'text', text: 'existing' }] }]
            const result = transformers.bard('new text', currentValue)

            expect(result).toHaveLength(2)
            expect(result[1]).toEqual({
                type: 'paragraph',
                content: [{ type: 'text', text: 'new text' }],
            })
        })

        it('creates paragraph from string data', () => {
            const result = transformers.bard('hello world', [])

            expect(result).toHaveLength(1)
            expect(result[0]).toEqual({
                type: 'paragraph',
                content: [{ type: 'text', text: 'hello world' }],
            })
        })

        it('extracts text from data.data property', () => {
            const input = { data: 'extracted text' }
            const result = transformers.bard(input, [])

            expect(result[0].content[0].text).toBe('extracted text')
        })

        it('preserves existing content', () => {
            const existing = [{ type: 'heading', content: [{ type: 'text', text: 'Title' }] }]
            const result = transformers.bard('body text', existing)

            expect(result[0]).toEqual(existing[0])
        })
    })

    describe('assets', () => {
        it('returns string data as-is', () => {
            expect(transformers.assets('alt text')).toBe('alt text')
        })

        it('extracts from data.data property', () => {
            const input = { data: 'asset description' }
            expect(transformers.assets(input)).toBe('asset description')
        })

        it('handles array data', () => {
            const input = ['tag1', 'tag2']
            expect(transformers.assets(input)).toEqual(['tag1', 'tag2'])
        })
    })
})
