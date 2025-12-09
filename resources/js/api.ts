import { sleep } from './helpers'
import type { JobContext, JobResponse, JobStatusResponse, RecoverableJob } from './types'

const ENDPOINTS = {
    completion: '/!/statamic-magic-actions/completion',
    vision: '/!/statamic-magic-actions/vision',
    transcription: '/!/statamic-magic-actions/transcribe',
    status: '/!/statamic-magic-actions/status',
    jobs: '/!/statamic-magic-actions/jobs',
    acknowledge: '/!/statamic-magic-actions/acknowledge',
    dismiss: '/!/statamic-magic-actions/dismiss',
} as const

export async function pollJobStatus(jobId: string, maxAttempts = 60, intervalMs = 1000): Promise {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const response = await window.Statamic.$axios.get<JobStatusResponse>(`${ENDPOINTS.status}/${jobId}`)
        const { status, data, error } = response.data

        if (status === 'completed') {
            return response.data
        }

        if (status === 'failed') {
            throw new Error(error || 'Job failed')
        }

        await sleep(intervalMs)
    }

    throw new Error('Timed out waiting for job to complete')
}

export async function executeCompletion(text: string, action: string, context?: JobContext): Promise {
    const payload: Record = { text, action }

    if (context) {
        payload.context_type = context.type
        payload.context_id = context.id
        payload.field_handle = context.field
    }

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.completion, payload)

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return {
        jobId: response.data.job_id,
        context: response.data.context,
    }
}

export async function executeVision(
    assetPath: string,
    action: string,
    variables: Record = {},
    context?: JobContext,
): Promise {
    const payload: Record = {
        asset_path: assetPath,
        action,
        variables,
    }

    if (context) {
        payload.context_type = context.type
        payload.context_id = context.id
        payload.field_handle = context.field
    }

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.vision, payload)

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return {
        jobId: response.data.job_id,
        context: response.data.context,
    }
}

export async function executeTranscription(assetPath: string, action: string, context?: JobContext): Promise {
    const payload: Record = {
        asset_path: assetPath,
        action,
    }

    if (context) {
        payload.context_type = context.type
        payload.context_id = context.id
        payload.field_handle = context.field
    }

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.transcription, payload)

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return {
        jobId: response.data.job_id,
        context: response.data.context,
    }
}

export async function getRecoverableJobs(context: JobContext): Promise {
    try {
        const response = await window.Statamic.$axios.get<{ jobs: RecoverableJob[] }>(
            `${ENDPOINTS.jobs}/${context.type}/${encodeURIComponent(context.id)}`,
        )
        return response.data.jobs || []
    } catch {
        return []
    }
}

export async function acknowledgeJob(jobId: string): Promise {
    await window.Statamic.$axios.post(`${ENDPOINTS.acknowledge}/${jobId}`, {})
}

export async function dismissJob(jobId: string): Promise {
    await window.Statamic.$axios.post(`${ENDPOINTS.dismiss}/${jobId}`, {})
}
