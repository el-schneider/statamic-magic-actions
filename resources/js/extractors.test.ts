import { describe, expect, it } from 'vitest'
import { extractAssetPathFromUrl, extractText, processApiResponse } from './extractors'

describe('extractText', () => {
    it('returns string content as-is', () => {
        expect(extractText('hello world')).toBe('hello world')
    })

    it('returns null/undefined as-is', () => {
        expect(extractText(null)).toBeNull()
        expect(extractText(undefined)).toBeUndefined()
    })

    it('extracts text from object with type:text and text property', () => {
        const input = { type: 'text', text: 'extracted text' }
        expect(extractText(input)).toBe('extracted text')
    })

    it('extracts and joins text from array of content', () => {
        const input = [
            { type: 'text', text: 'line 1' },
            { type: 'text', text: 'line 2' },
        ]
        expect(extractText(input)).toBe('line 1\nline 2')
    })

    it('recursively extracts text from nested objects', () => {
        const input = {
            paragraph: { type: 'text', text: 'nested text' },
        }
        expect(extractText(input)).toBe('nested text')
    })

    it('handles Bard content structure', () => {
        const bardContent = [
            {
                type: 'paragraph',
                content: [{ type: 'text', text: 'First paragraph' }],
            },
            {
                type: 'paragraph',
                content: [{ type: 'text', text: 'Second paragraph' }],
            },
        ]
        expect(extractText(bardContent)).toBe('First paragraph\nSecond paragraph')
    })

    it('filters out empty values', () => {
        const input = [{ type: 'text', text: 'valid' }, null, { type: 'text', text: '' }]
        expect(extractText(input)).toBe('valid')
    })

    it('handles deeply nested content', () => {
        const input = {
            level1: {
                level2: {
                    type: 'text',
                    text: 'deep text',
                },
            },
        }
        expect(extractText(input)).toBe('deep text')
    })
})

describe('processApiResponse', () => {
    it('extracts string from data property', () => {
        const response = { data: 'response text' }
        expect(processApiResponse(response)).toBe('response text')
    })

    it('extracts content from content property', () => {
        const response = { content: 'some content' }
        expect(processApiResponse(response)).toEqual({ data: 'some content' })
    })

    it('extracts text from text property', () => {
        const response = { text: 'some text' }
        expect(processApiResponse(response)).toEqual({ data: 'some text' })
    })

    it('parses JSON array from content', () => {
        const response = { content: 'Here are the tags: ["tag1", "tag2", "tag3"]' }
        expect(processApiResponse(response)).toEqual(['tag1', 'tag2', 'tag3'])
    })

    it('parses JSON object from content', () => {
        const response = { content: 'Result: {"key": "value"}' }
        expect(processApiResponse(response)).toEqual({ key: 'value' })
    })

    it('returns response as-is when no known properties exist', () => {
        const response = { unknown: 'property' }
        expect(processApiResponse(response)).toEqual({ unknown: 'property' })
    })

    it('handles null and undefined', () => {
        expect(processApiResponse(null)).toBeNull()
        expect(processApiResponse(undefined)).toBeUndefined()
    })

    it('returns non-object data as-is', () => {
        expect(processApiResponse('string')).toBe('string')
        expect(processApiResponse(123)).toBe(123)
    })

    it('handles multiline JSON in content', () => {
        const response = {
            content: `Here is the result:
{
  "title": "Test",
  "value": 42
}`,
        }
        expect(processApiResponse(response)).toEqual({ title: 'Test', value: 42 })
    })
})

describe('extractAssetPathFromUrl', () => {
    it('extracts asset path from standard asset edit URL', () => {
        const url = '/cp/assets/browse/images/photo.jpg/edit'
        expect(extractAssetPathFromUrl(url)).toBe('images::photo.jpg')
    })

    it('extracts asset path with nested directory', () => {
        const url = '/cp/assets/browse/media/photos/2024/vacation.png/edit'
        expect(extractAssetPathFromUrl(url)).toBe('media::photos/2024/vacation.png')
    })

    it('returns null for non-matching URLs', () => {
        expect(extractAssetPathFromUrl('/cp/collections/pages/edit')).toBeNull()
        expect(extractAssetPathFromUrl('/some/other/path')).toBeNull()
    })

    it('handles container names with hyphens', () => {
        const url = '/cp/assets/browse/my-assets/image.jpg/edit'
        expect(extractAssetPathFromUrl(url)).toBe('my-assets::image.jpg')
    })

    it('handles filenames with special characters', () => {
        const url = '/cp/assets/browse/assets/my-image_v2.jpg/edit'
        expect(extractAssetPathFromUrl(url)).toBe('assets::my-image_v2.jpg')
    })
})
