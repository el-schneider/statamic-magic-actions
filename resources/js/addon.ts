/// <reference types="vite/client" />

declare global {
    interface Window {
        StatamicConfig: {
            magicFields: Array<{
                type: string
                action: string
                prompt: string
                promptType?: string
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
                success: (message: string) => void
                info: (message: string) => void
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

interface JobContext {
    type: string // 'entry' or 'asset'
    id: string
    field: string
}

interface TrackedJob {
    jobId: string
    action: string
    fieldHandle: string
    fieldType: string
    startedAt: string
    context: JobContext
}

interface JobStatus {
    status: 'queued' | 'processing' | 'completed' | 'failed'
    message?: string
    data?: any
    error?: string
    context?: JobContext
}

/**
 * Manages localStorage persistence for job tracking across page navigations.
 */
class JobStorage {
    private static STORAGE_KEY_PREFIX = 'magic_actions_jobs_'

    static getStorageKey(context: JobContext): string {
        return `${this.STORAGE_KEY_PREFIX}${context.type}_${context.id.replace(/[/:]/g, '_')}`
    }

    static getTrackedJobs(context: JobContext): TrackedJob[] {
        try {
            const key = this.getStorageKey(context)
            const stored = localStorage.getItem(key)
            return stored ? JSON.parse(stored) : []
        } catch {
            return []
        }
    }

    static trackJob(job: TrackedJob): void {
        const jobs = this.getTrackedJobs(job.context)
        const existingIndex = jobs.findIndex((j) => j.jobId === job.jobId)

        if (existingIndex >= 0) {
            jobs[existingIndex] = job
        } else {
            jobs.push(job)
        }

        localStorage.setItem(this.getStorageKey(job.context), JSON.stringify(jobs))
    }

    static removeJob(context: JobContext, jobId: string): void {
        const jobs = this.getTrackedJobs(context).filter((j) => j.jobId !== jobId)

        if (jobs.length === 0) {
            localStorage.removeItem(this.getStorageKey(context))
        } else {
            localStorage.setItem(this.getStorageKey(context), JSON.stringify(jobs))
        }
    }

    static clearContext(context: JobContext): void {
        localStorage.removeItem(this.getStorageKey(context))
    }
}

/**
 * Core service for managing magic action API calls with background job support.
 */
class MagicActionsService {
    private endpoints = {
        completion: '/!/statamic-magic-actions/completion',
        vision: '/!/statamic-magic-actions/vision',
        transcription: '/!/statamic-magic-actions/transcribe',
        status: '/!/statamic-magic-actions/status',
        jobs: '/!/statamic-magic-actions/jobs',
        acknowledge: '/!/statamic-magic-actions/acknowledge',
        dismiss: '/!/statamic-magic-actions/dismiss',
    }

    /**
     * Poll for job status until completed or failed.
     */
    async pollJobStatus(jobId: string, maxAttempts = 60, interval = 1000): Promise<JobStatus> {
        let attempts = 0

        while (attempts < maxAttempts) {
            try {
                const response = await window.Statamic.$axios.get(`${this.endpoints.status}/${jobId}`)
                const jobStatus: JobStatus = response.data

                if (jobStatus.status === 'completed') {
                    return jobStatus
                }

                if (jobStatus.status === 'failed') {
                    throw new Error(jobStatus.error || 'Job failed')
                }

                await new Promise((resolve) => setTimeout(resolve, interval))
                attempts++
            } catch (error: any) {
                throw new Error(`Failed to get job status: ${error.message}`)
            }
        }

        throw new Error('Timed out waiting for job to complete')
    }

    /**
     * Get recoverable jobs for a context from the server.
     */
    async getRecoverableJobs(context: JobContext): Promise<any[]> {
        try {
            const response = await window.Statamic.$axios.get(
                `${this.endpoints.jobs}/${context.type}/${encodeURIComponent(context.id)}`,
            )
            return response.data.jobs || []
        } catch {
            return []
        }
    }

    /**
     * Acknowledge a job (mark as applied).
     */
    async acknowledgeJob(jobId: string): Promise<void> {
        await window.Statamic.$axios.post(`${this.endpoints.acknowledge}/${jobId}`, {})
    }

    /**
     * Dismiss a job without applying.
     */
    async dismissJob(jobId: string): Promise<void> {
        await window.Statamic.$axios.post(`${this.endpoints.dismiss}/${jobId}`, {})
    }

    /**
     * Execute a completion request with context tracking.
     */
    async executeCompletion(text: string, action: string, context?: JobContext): Promise<any> {
        const payload: any = { text, action }

        if (context) {
            payload.context_type = context.type
            payload.context_id = context.id
            payload.field_handle = context.field
        }

        const response = await window.Statamic.$axios.post(this.endpoints.completion, payload)

        if (!response.data.job_id) {
            throw new Error('No job ID returned from the server')
        }

        return {
            jobId: response.data.job_id,
            context: response.data.context,
        }
    }

    /**
     * Execute a vision request with context tracking.
     */
    async executeVision(
        assetPath: string,
        action: string,
        variables: Record<string, string> = {},
        context?: JobContext,
    ): Promise<any> {
        const payload: any = {
            asset_path: assetPath,
            action,
            variables,
        }

        if (context) {
            payload.context_type = context.type
            payload.context_id = context.id
            payload.field_handle = context.field
        }

        const response = await window.Statamic.$axios.post(this.endpoints.vision, payload)

        if (!response.data.job_id) {
            throw new Error('No job ID returned from the server')
        }

        return {
            jobId: response.data.job_id,
            context: response.data.context,
        }
    }

    /**
     * Execute a transcription request with context tracking.
     */
    async executeTranscription(assetPath: string, action: string, context?: JobContext): Promise<any> {
        const payload: any = {
            asset_path: assetPath,
            action,
        }

        if (context) {
            payload.context_type = context.type
            payload.context_id = context.id
            payload.field_handle = context.field
        }

        const response = await window.Statamic.$axios.post(this.endpoints.transcription, payload)

        if (!response.data.job_id) {
            throw new Error('No job ID returned from the server')
        }

        return {
            jobId: response.data.job_id,
            context: response.data.context,
        }
    }

    /**
     * Process API response to extract usable data.
     */
    processApiResponse(response: any): any {
        if (response.data && typeof response.data === 'string') {
            return response.data
        }

        if (response.content) {
            try {
                const jsonMatch = response.content.match(/(\[.*\]|\{.*\})/s)?.[0]
                if (jsonMatch) {
                    return JSON.parse(jsonMatch)
                }
            } catch {
                // Not JSON, return as is
            }
            return { data: response.content }
        }

        if (response.text) {
            return { data: response.text }
        }

        return response
    }
}

const service = new MagicActionsService()

/**
 * Extract text content from various field value formats.
 */
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

/**
 * Transform API responses to field-specific formats.
 */
const transformerMap: Record<string, (data: any, value?: any) => any> = {
    text: (data: any) => {
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            return data.data
        }
        return typeof data === 'string' ? data : String(data)
    },
    tags: (data: any) => {
        let content = data
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            content = data.data
        }
        if (Array.isArray(content)) {
            return content.slice(0, 10)
        }
        if (typeof content === 'string') {
            const matches = content.match(/"([^"]*)"/g)
            if (matches) {
                return matches.map((m) => m.replace(/"/g, '')).slice(0, 10)
            }
            return content
                .split(',')
                .map((t) => t.trim())
                .slice(0, 10)
        }
        return [content]
    },
    terms: (data: any) => {
        let content = data
        if (data && typeof data === 'object' && data.data && typeof data.data === 'string') {
            content = data.data
        }
        if (Array.isArray(content)) {
            return content.slice(0, 10)
        }
        if (typeof content === 'string') {
            const matches = content.match(/"([^"]*)"/g)
            if (matches) {
                return matches.map((m) => m.replace(/"/g, '')).slice(0, 10)
            }
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
    assets: (data: string) => data,
}

/**
 * Extract context information from the current page.
 */
function extractPageContext(): JobContext | null {
    const url = window.location.pathname

    // Entry context: /cp/collections/{collection}/entries/{entryId}
    const entryMatch = url.match(/\/cp\/collections\/([^/]+)\/entries\/([^/]+)/)
    if (entryMatch) {
        return {
            type: 'entry',
            id: entryMatch[2],
            field: '', // Will be set per-field
        }
    }

    // Asset context: /cp/assets/browse/{container}/{path}/edit
    const assetMatch = url.match(/\/cp\/assets\/browse\/(.+?)\/edit/)
    if (assetMatch) {
        return {
            type: 'asset',
            id: assetMatch[1],
            field: '', // Will be set per-field
        }
    }

    return null
}

/**
 * Apply a completed job result to a field.
 */
function applyJobResult(
    job: any,
    fieldType: string,
    fieldConfig: any,
    currentValue: any,
    update: (value: any) => void,
): void {
    const transformer = transformerMap[fieldType] || transformerMap.text
    const mode = fieldConfig?.magic_actions_mode || 'append'
    const data = job.data

    let newValue
    if (mode === 'append') {
        if (Array.isArray(currentValue)) {
            newValue = [...currentValue, ...transformer(data, currentValue)]
        } else if (typeof currentValue === 'object' && currentValue !== null) {
            newValue = [...(currentValue?.length ? currentValue : []), ...transformer(data, currentValue)]
        } else {
            newValue = transformer(data, currentValue)
        }
    } else {
        newValue = transformer(data, Array.isArray(currentValue) ? [] : currentValue)
    }

    update(newValue)
}

/**
 * Check for and recover any pending background jobs when page loads.
 */
async function recoverBackgroundJobs(
    context: JobContext,
    store: any,
    storeName: string,
    magicFields: any[],
): Promise<void> {
    // Get tracked jobs from localStorage
    const trackedJobs = JobStorage.getTrackedJobs(context)

    if (trackedJobs.length === 0) {
        return
    }

    // Also fetch from server to get latest status
    const serverJobs = await service.getRecoverableJobs(context)
    const serverJobMap = new Map(serverJobs.map((j: any) => [j.job_id, j]))

    for (const tracked of trackedJobs) {
        const serverJob = serverJobMap.get(tracked.jobId)

        if (!serverJob) {
            // Job no longer exists on server, clean up
            JobStorage.removeJob(context, tracked.jobId)
            continue
        }

        const fieldContext: JobContext = { ...context, field: tracked.fieldHandle }

        if (serverJob.status === 'completed' && serverJob.data) {
            // Show notification about completed job
            const fieldConfig = magicFields.find((f) => f.action === tracked.action)
            const fieldTitle = fieldConfig?.title || tracked.action

            window.Statamic.$toast.info(`Magic action "${fieldTitle}" completed in background`)

            // Try to find the field in the store and apply the result
            try {
                const state = store.state.publish[storeName]
                if (state && state.values) {
                    // Find the field config from blueprint
                    const blueprintField = magicFields.find((f) => f.action === tracked.action)

                    if (blueprintField) {
                        const transformer = transformerMap[tracked.fieldType] || transformerMap.text
                        const currentValue = state.values[tracked.fieldHandle]
                        const newValue = transformer(serverJob.data, currentValue)

                        // Update the field value in the store
                        if (state.values.hasOwnProperty(tracked.fieldHandle)) {
                            state.values[tracked.fieldHandle] = newValue
                            window.Statamic.$toast.success(`Applied result to "${tracked.fieldHandle}" field`)
                        }
                    }
                }

                // Acknowledge the job
                await service.acknowledgeJob(tracked.jobId)
            } catch (error) {
                console.error('Error applying recovered job result:', error)
            }

            // Clean up from localStorage
            JobStorage.removeJob(fieldContext, tracked.jobId)
        } else if (serverJob.status === 'failed') {
            // Notify about failed job
            window.Statamic.$toast.error(`Background job for "${tracked.fieldHandle}" failed: ${serverJob.error}`)

            // Clean up
            await service.dismissJob(tracked.jobId)
            JobStorage.removeJob(fieldContext, tracked.jobId)
        } else if (serverJob.status === 'queued' || serverJob.status === 'processing') {
            // Job still running, show notification
            window.Statamic.$toast.info(`Magic action for "${tracked.fieldHandle}" is still processing...`)

            // Continue polling in background
            pollJobInBackground(tracked, fieldContext)
        }
    }
}

/**
 * Continue polling a job in the background (used for recovery).
 */
async function pollJobInBackground(tracked: TrackedJob, context: JobContext): Promise<void> {
    try {
        const result = await service.pollJobStatus(tracked.jobId)

        if (result.status === 'completed') {
            window.Statamic.$toast.success(`Magic action for "${tracked.fieldHandle}" completed!`)

            // Note: We can't easily update the field value here since we don't have the update function.
            // The user will need to refresh to see the result, or we rely on the next page load recovery.
            await service.acknowledgeJob(tracked.jobId)
        }
    } catch (error: any) {
        window.Statamic.$toast.error(`Background job failed: ${error.message}`)
        await service.dismissJob(tracked.jobId)
    } finally {
        JobStorage.removeJob(context, tracked.jobId)
    }
}

/**
 * Register field actions for each configured magic field.
 */
const registerFieldActions = async () => {
    try {
        const magicFields = window.StatamicConfig?.magicFields
        if (!magicFields || !Array.isArray(magicFields)) {
            return
        }

        // Get page context for job recovery
        const pageContext = extractPageContext()

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
                            const state = store.state.publish[storeName]

                            // Build context for this specific field
                            const fieldContext: JobContext | undefined = pageContext
                                ? {
                                      ...pageContext,
                                      field: handle,
                                  }
                                : undefined

                            // Determine action type
                            let type: string = 'completion'
                            if (field.promptType === 'audio') {
                                type = 'transcription'
                            } else if (field.promptType === 'text') {
                                const sourceValue = state.values[config.magic_actions_source]
                                let isAssetField = false

                                if (typeof sourceValue === 'string' && sourceValue.includes('::')) {
                                    isAssetField = true
                                } else if (
                                    Array.isArray(sourceValue) &&
                                    sourceValue.length > 0 &&
                                    typeof sourceValue[0] === 'string' &&
                                    sourceValue[0].includes('::')
                                ) {
                                    isAssetField = true
                                }

                                if (isAssetField) {
                                    type = 'vision'
                                }
                            }

                            let sourceValue: string
                            let assetPath: string | undefined = undefined

                            // Get source value based on action type
                            if (type === 'vision' || type === 'transcription') {
                                const url = window.location.pathname
                                const match = url.match(/browse\/([^/]+)\/(.+?)\/edit/)
                                if (match) {
                                    assetPath = `${match[1]}::${match[2]}`
                                } else {
                                    assetPath = state.values[config.magic_actions_source]?.[0] || undefined
                                }

                                if (!assetPath) {
                                    throw new Error('No asset selected')
                                }

                                sourceValue = type === 'vision' ? 'Analyze this image' : ''
                            } else {
                                sourceValue = extractText(state.values[config.magic_actions_source])
                                if (!sourceValue) {
                                    throw new Error('Source field is empty')
                                }
                            }

                            // Start the job
                            let jobResult: { jobId: string; context?: JobContext }

                            if (type === 'vision') {
                                jobResult = await service.executeVision(assetPath!, field.action, {}, fieldContext)
                            } else if (type === 'transcription') {
                                jobResult = await service.executeTranscription(assetPath!, field.action, fieldContext)
                            } else {
                                jobResult = await service.executeCompletion(sourceValue, field.action, fieldContext)
                            }

                            // Track the job in localStorage for recovery
                            if (fieldContext) {
                                JobStorage.trackJob({
                                    jobId: jobResult.jobId,
                                    action: field.action,
                                    fieldHandle: handle,
                                    fieldType: field.type,
                                    startedAt: new Date().toISOString(),
                                    context: fieldContext,
                                })
                            }

                            // Show processing indicator
                            window.Statamic.$toast.info('Magic action started. You can navigate away safely.')

                            // Poll for result
                            const status = await service.pollJobStatus(jobResult.jobId)

                            // Clean up tracking
                            if (fieldContext) {
                                JobStorage.removeJob(fieldContext, jobResult.jobId)
                            }

                            // Acknowledge the job on the server
                            if (jobResult.context) {
                                await service.acknowledgeJob(jobResult.jobId)
                            }

                            // Apply the result
                            const data = service.processApiResponse(status)
                            applyJobResult({ data }, field.type, config, value, update)

                            window.Statamic.$toast.success('Magic action completed!')
                        } catch (error: any) {
                            console.error('Error in Magic Actions:', error)
                            window.Statamic.$toast.error(error.message || 'Failed to process the action')
                        }
                    },
                })
            }
        })

        // After registering actions, check for recoverable jobs
        if (pageContext) {
            // Small delay to ensure store is initialized
            setTimeout(() => {
                try {
                    const store = window.Statamic.Store.store
                    // Find the active store name
                    const storeNames = Object.keys(store.state.publish || {})
                    if (storeNames.length > 0) {
                        const storeName = storeNames[0]
                        recoverBackgroundJobs(pageContext, store, storeName, magicFields)
                    }
                } catch (error) {
                    console.error('Error recovering background jobs:', error)
                }
            }, 1000)
        }
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
