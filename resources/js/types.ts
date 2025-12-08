/// <reference types="vite/client" />

export interface MagicField {
    component: string
    title: string
    action: string
    promptType: 'text' | 'audio'
    type: string
}

export interface PublishState {
    values: Record
}

export interface FieldActionConfig {
    title: string
    quick: boolean
    visible: (context: { config: FieldConfig }) => boolean
    icon: string
    run: (context: RunContext) => Promise
}

export interface FieldConfig {
    magic_actions_enabled?: boolean
    magic_actions_action?: string
    magic_actions_source?: string
    magic_actions_mode?: 'replace' | 'append'
}

export interface RunContext {
    handle: string
    value: unknown
    update: (value: unknown) => void
    store: Window['Statamic']['Store']['store']
    storeName: string
    config: FieldConfig
}

export interface JobStatusResponse {
    status: 'completed' | 'failed' | 'processing'
    data?: string
    error?: string
}

export interface JobResponse {
    job_id: string
}

export type ActionType = 'completion' | 'vision' | 'transcription'

declare global {
    interface Window {
        StatamicConfig: {
            magicFields: MagicField[]
            providers: {
                openai?: { api_key: string }
                google?: { api_key: string }
            }
        }
        Statamic: {
            $fieldActions: {
                add: (type: string, config: FieldActionConfig) => void
            }
            $toast: {
                error: (message: string) => void
            }
            $axios: {
                get: <T>(url: string) => Promise
                post: <T>(url: string, data: unknown) => Promise
            }
            Store: {
                store: {
                    state: {
                        publish: Record
                    }
                }
            }
        }
    }
}
