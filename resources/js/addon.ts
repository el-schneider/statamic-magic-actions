/// <reference types="vite/client" />

declare global {
    interface Window {
        StatamicConfig: {
            magicFields: Array<{
                type: string
                action: string
                prompt: string
            }>
            providers: {
                openai?: {
                    api_key: string
                }
                google?: {
                    api_key: string
                }
            }
        }
        Statamic: {
            $fieldActions: {
                add: (type: string, config: any) => void
            }
            $toast: {
                error: (message: string) => void
            }
            $axios: {
                get: (url: string) => Promise<{ data: any }>
                post: (url: string, data: any) => Promise<{ data: any }>
            }
            Store: {
                store: {
                    state: {
                        publish: Record<string, any>
                    }
                }
            }
        }
    }
}

class MagicActionsService {
    private endpoints = {
        completion: '/!/statamic-magic-actions/completion',
        vision: '/!/statamic-magic-actions/vision',
        transcription: '/!/statamic-magic-actions/transcribe',
        status: '/!/statamic-magic-actions/status',
    }

    constructor() {}

    // Poll for job status until it's completed or failed
    async pollJobStatus(jobId: string, maxAttempts = 60, interval = 1000): Promise<any> {
        let attempts = 0

        console.log(`Starting to poll job status for job ID: ${jobId}`)

        while (attempts < maxAttempts) {
            try {
                console.log(`Polling attempt ${attempts + 1} for job ID: ${jobId}`)
                console.log(`Making request to: ${this.endpoints.status}/${jobId}`)

                const response = await window.Statamic.$axios.get(`${this.endpoints.status}/${jobId}`)
                const jobStatus = response.data

                console.log(`Job status response:`, jobStatus)

                if (jobStatus.status === 'completed') {
                    console.log(`Job ${jobId} completed successfully`)
                    return jobStatus.data
                }

                if (jobStatus.status === 'failed') {
                    console.error(`Job ${jobId} failed:`, jobStatus.error)
                    throw new Error(jobStatus.error || 'Job failed')
                }

                console.log(`Job ${jobId} still processing, waiting ${interval}ms before next attempt`)
                // If still processing, wait and try again
                await new Promise((resolve) => setTimeout(resolve, interval))
                attempts++
            } catch (error) {
                console.error(`Error polling job ${jobId}:`, error)
                console.error(`Full error details:`, error.response || error)
                throw new Error(`Failed to get job status: ${error.message}`)
            }
        }

        console.error(`Timed out waiting for job ${jobId} to complete after ${maxAttempts} attempts`)
        throw new Error('Timed out waiting for job to complete')
    }

    // Completion endpoint
    async executeCompletion(text: string, promptHandle: string): Promise<any> {
        try {
            console.log(`Starting completion request for prompt: ${promptHandle}`)
            console.log(`Request payload:`, { text, action: promptHandle })

            const response = await window.Statamic.$axios.post(this.endpoints.completion, {
                text,
                action: promptHandle,
            })

            console.log(`Completion response:`, response.data)

            if (!response.data.job_id) {
                console.error('No job_id returned from API')
                throw new Error('No job ID returned from the server')
            }

            console.log(`Starting polling for job: ${response.data.job_id}`)
            return this.pollJobStatus(response.data.job_id)
        } catch (error) {
            console.error('Error in executeCompletion:', error)
            console.error('Full error details:', error.response || error)
            throw new Error(error.response?.data?.error || error.message)
        }
    }

    // Vision endpoint
    async executeVision(assetPath: string, promptHandle: string, variables: Record<string, string> = {}): Promise<any> {
        try {
            const { data } = await window.Statamic.$axios.post(this.endpoints.vision, {
                asset_path: assetPath,
                action: promptHandle,
                variables,
            })

            return this.pollJobStatus(data.job_id)
        } catch (error) {
            throw new Error(error.response?.data?.error || error.message)
        }
    }

    // Transcription endpoint
    async executeTranscription(assetPath: string, promptHandle: string): Promise<any> {
        try {
            const { data } = await window.Statamic.$axios.post(this.endpoints.transcription, {
                asset_path: assetPath,
                action: promptHandle,
            })

            return this.pollJobStatus(data.job_id)
        } catch (error) {
            throw new Error(error.response?.data?.error || error.message)
        }
    }

    // Extract content from API response
    processApiResponse(response: any): any {
        // Handle direct string in data property (from job response)
        if (response.data && typeof response.data === 'string') {
            return response.data
        }

        // Handling different response formats
        if (response.content) {
            // Try to parse JSON content if it exists
            try {
                const jsonMatch = response.content.match(/(\[.*\]|\{.*\})/s)?.[0]
                if (jsonMatch) {
                    return JSON.parse(jsonMatch)
                }
            } catch {
                // If not JSON, return as is
            }

            return { data: response.content }
        }

        if (response.text) {
            return { data: response.text }
        }

        return response
    }

    // Main method to route to the correct endpoint
    async generateFromPrompt(
        text: string,
        promptHandle: string,
        type: string = 'completion',
        assetPath?: string,
    ): Promise<any> {
        try {
            let response

            switch (type) {
                case 'completion':
                    response = await this.executeCompletion(text, promptHandle)
                    break
                case 'vision':
                    if (!assetPath) {
                        throw new Error('Asset ID is required for vision requests')
                    }
                    response = await this.executeVision(assetPath, promptHandle, { text })
                    break
                case 'transcription':
                    if (!assetPath) {
                        throw new Error('Asset ID is required for transcription requests')
                    }
                    response = await this.executeTranscription(assetPath, promptHandle)
                    break
                default:
                    throw new Error(`Unsupported type: ${type}`)
            }

            return this.processApiResponse(response)
        } catch (error) {
            console.error('Magic Actions error:', error)
            throw error
        }
    }
}

const service = new MagicActionsService()

// Utility functions
const extractText = (content: any): string => {
    if (!content || typeof content === 'string') return content

    if (content.type === 'text' && content.text) {
        return content.text
    }

    if (Array.isArray(content)) {
        return content.map(extractText).filter(Boolean).join('\n')
    }

    return Object.values(content).map(extractText).filter(Boolean).join('\n')
}

// Register field actions for each fieldtype with magic tags enabled
const magicActionsService = new MagicActionsService()

const transformerMap = {
    text: (data: any) => {
        // Extract string from data property if it exists, otherwise return as-is
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            return data.data
        }
        return typeof data === 'string' ? data : String(data)
    },
    tags: (data: any) => {
        // Extract string from data property if it exists
        let content = data
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            content = data.data
        }
        if (Array.isArray(content)) {
            return content.slice(0, 10)
        }
        if (typeof content === 'string') {
            // Parse quoted CSV format: "tag1", "tag2", "tag3"
            const matches = content.match(/"([^"]*)"/g)
            if (matches) {
                return matches.map((m) => m.replace(/"/g, '')).slice(0, 10)
            }
            // Fallback to comma-separated
            return content
                .split(',')
                .map((t) => t.trim())
                .slice(0, 10)
        }
        return [content]
    },
    terms: (data: any) => {
        // Extract string from data property if it exists
        let content = data
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            content = data.data
        }
        if (Array.isArray(content)) {
            return content.slice(0, 10)
        }
        if (typeof content === 'string') {
            // Parse quoted CSV format: "tag1", "tag2", "tag3"
            const matches = content.match(/"([^"]*)"/g)
            if (matches) {
                return matches.map((m) => m.replace(/"/g, '')).slice(0, 10)
            }
            // Fallback to comma-separated
            return content
                .split(',')
                .map((t) => t.trim())
                .slice(0, 10)
        }
        return [content]
    },
    bard: (data: string, value: any) => [
        ...value,
        {
            type: 'paragraph',
            content: [
                {
                    type: 'text',
                    text: data,
                },
            ],
        },
    ],
    assets: (data: string, value: any) => {
        // Return data as is, this could be alt text or tags for assets
        return data
    },
}

const registerFieldActions = async () => {
    try {
        const map = {
            terms: 'relationship-fieldtype',
        }

        const magicFields = window.StatamicConfig?.magicFields
        if (!magicFields || !Array.isArray(magicFields)) {
            console.log('No magic fields configured for this page')
            return
        }

        magicFields.forEach((field: any) => {
            const componentName = `${field.component}-fieldtype`

            if (componentName) {
                window.Statamic.$fieldActions.add(componentName, {
                    title: field.title,
                    quick: true,
                    visible: ({ config }: any) =>
                        config?.magic_actions_enabled && config?.magic_actions_action === field.action,
                    icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>`,
                    run: async ({ handle, value, update, store, storeName, config }) => {
                        try {
                            console.log(`Starting field action run for field:`, field)
                            console.log(`Field config:`, config)
                            console.log(`Store:`, store, storeName)

                            const state = store.state.publish[storeName]
                            console.log(`State:`, state)
                            console.log(`State values:`, state.values)

                            const type = field.action.includes('vision')
                                ? 'vision'
                                : field.action.includes('transcribe')
                                  ? 'transcription'
                                  : 'completion'

                            console.log(`Action type: ${type}, Action handle: ${field.action}`)
                            let sourceValue
                            let assetPath: string | undefined = undefined

                            // For vision and transcription, we need the asset ID
                            if (type === 'vision' || type === 'transcription') {
                                // Extract asset path from URL
                                const url = window.location.pathname
                                const match = url.match(/browse(.+?)\/edit/)
                                assetPath = match
                                    ? match[1]
                                    : state.values[config.magic_actions_source]?.[0] || undefined

                                console.log(`Asset ID:`, assetPath)
                                if (!assetPath) {
                                    throw new Error('No asset selected')
                                }

                                // For vision, we still need some text to analyze with
                                sourceValue = type === 'vision' ? 'Analyze this image' : ''
                            } else {
                                // For text completion, extract the text from the source field
                                sourceValue = extractText(state.values[config.magic_actions_source])
                                if (!sourceValue) {
                                    throw new Error('Source field is empty')
                                }
                            }

                            console.log(`Calling generateFromPrompt with params:`, {
                                sourceValue,
                                promptHandle: field.action,
                                type,
                                assetPath,
                            })

                            const data = await magicActionsService.generateFromPrompt(
                                sourceValue,
                                field.action,
                                type,
                                assetPath,
                            )

                            console.log(`API response data:`, data)
                            const transformer = transformerMap[field.type] || transformerMap.text

                            const mode = config.magic_actions_mode || 'append'
                            let newValue

                            if (mode === 'append') {
                                if (Array.isArray(value)) {
                                    newValue = [...value, ...transformer(data, value)]
                                } else if (typeof value === 'object' && value !== null) {
                                    newValue = [...(value?.length ? value : []), ...transformer(data, value)]
                                } else {
                                    newValue = transformer(data, value)
                                }
                            } else {
                                newValue = transformer(data, Array.isArray(value) ? [] : value)
                            }

                            update(newValue)
                        } catch (error) {
                            console.error('Error in Magic Actions:', error)
                            window.Statamic.$toast.error(error.message || 'Failed to process the action')
                        }
                    },
                })
            }
        })
    } catch (error) {
        console.error('Error registering field actions:', error)
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
    registerFieldActions()
}
export default {}
