import { sleep } from './helpers'
import type { JobContext, JobResponse, JobStatusResponse } from './types'

const ENDPOINTS = {
    completion: '/!/statamic-magic-actions/completion',
    vision: '/!/statamic-magic-actions/vision',
    transcription: '/!/statamic-magic-actions/transcribe',
    status: '/!/statamic-magic-actions/status',
} as const

export interface PollResult {
    data: string
}

export async function pollJobStatus(jobId: string, maxAttempts = 120, intervalMs = 1000): Promise<PollResult> {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const response = await window.Statamic.$axios.get<JobStatusResponse>(`${ENDPOINTS.status}/${jobId}`)
        const { status, data, error } = response.data

        if (status === 'completed') {
            return { data: data ?? '' }
        }

        if (status === 'failed') {
            throw new Error(error || 'Job failed')
        }

        await sleep(intervalMs)
    }

    throw new Error('Timed out waiting for job to complete')
}

export async function executeCompletion(
    text: string,
    action: string,
    context?: JobContext,
): Promise<{ jobId: string }> {
    const payload: Record<string, unknown> = { text, action }

    if (context) {
        payload.context_type = context.type
        payload.context_id = context.id
        payload.field_handle = context.field
    }

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.completion, payload)

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return { jobId: response.data.job_id }
}

export async function executeVision(
    assetPath: string,
    action: string,
    variables: Record<string, unknown> = {},
    context?: JobContext,
): Promise<{ jobId: string }> {
    const payload: Record<string, unknown> = {
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

    return { jobId: response.data.job_id }
}

export async function executeTranscription(
    assetPath: string,
    action: string,
    context?: JobContext,
): Promise<{ jobId: string }> {
    const payload: Record<string, unknown> = {
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

    return { jobId: response.data.job_id }
}
