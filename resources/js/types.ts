export interface MagicField {
    type: string
    action: string
    prompt: string
    promptType?: string
    component: string
    title: string
}

export interface ApiResponse {
    data?: string | unknown
    content?: string
    text?: string
}

export interface BardNode {
    type: string
    content?: BardNode[]
    text?: string
}

export type TransformerFn<T = unknown> = (data: unknown, currentValue?: T) => T
