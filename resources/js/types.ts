/// <reference types="vite/client" />

export interface MagicFieldAction {
    title: string
    handle: string
    actionType: 'text' | 'vision' | 'audio'
    icon: string | null
    acceptedMimeTypes: string[]
}

export type MagicActionCatalog = Record<string, MagicFieldAction[]>

export interface PublishState {
    values: Record<string, unknown>
    site?: string
}

export interface FieldActionConfig {
    title: string
    quick: boolean
    visible: (context: { config: FieldConfig; handle: string }) => boolean
    icon: string
    run: (context: RunContext) => Promise<void>
}

export interface FieldConfig {
    magic_actions_enabled?: boolean
    magic_actions_action?: string | string[]
    magic_actions_source?: string
    magic_actions_mode?: 'replace' | 'append'
}

export interface FieldMeta {
    itemDataUrl?: string
    data?: unknown[]
    [key: string]: unknown
}

export interface FieldActionVm {
    site?: string
    itemDataUrl?: string
    meta?: FieldMeta | null
}

export interface RelationshipMetaSyncContext {
    meta: FieldMeta
    updateMeta: (value: FieldMeta) => void
    itemDataUrl: string
    site?: string
    vm?: FieldActionVm
}

export interface RunContext {
    handle: string
    value: unknown
    update: (value: unknown) => void
    updateMeta: (value: FieldMeta) => void
    meta: FieldMeta | null
    vm: FieldActionVm | null
    store: Window['Statamic']['Store']['store']
    storeName: string
    config: FieldConfig
}

export interface JobStatusResponse {
    status: 'queued' | 'processing' | 'completed' | 'failed'
    data?: unknown
    error?: string
    context?: JobContext
}

export interface JobResponse {
    job_id: string
    context?: JobContext
}

export type ActionType = 'completion' | 'vision' | 'transcription'

export interface JobContext {
    type: string // 'entry' or 'asset'
    id: string
    field: string
}

declare global {
    interface Window {
        StatamicConfig: {
            magicActionCatalog?: MagicActionCatalog
        }
        Statamic: {
            $config: {
                get: (key: string, fallback?: string) => string
                cpRoot?: string
            }
            $fieldActions: {
                add: (type: string, config: FieldActionConfig) => void
            }
            $toast: {
                error: (message: string) => void
                success: (message: string) => void
                info: (message: string) => void
            }
            $axios: {
                get: <T>(url: string) => Promise<{ data: T }>
                post: <T>(url: string, data: unknown) => Promise<{ data: T }>
            }
            Store: {
                store: {
                    state: {
                        publish: Record<string, PublishState>
                    }
                }
            }
        }
    }
}
