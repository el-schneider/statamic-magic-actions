import { sleep } from './helpers'
import type { JobContext, JobResponse, JobStatusResponse } from './types'

const cpRootFromConfig =
    window.Statamic?.$config && typeof window.Statamic.$config.get === 'function'
        ? window.Statamic.$config.get('cpRoot', '/cp')
        : window.Statamic?.$config?.cpRoot

const cpRoot = String(cpRootFromConfig ?? '/cp').replace(/\/+$/, '')
const magicActionsRoot = `${cpRoot}/magic-actions`

const ENDPOINTS = {
    completion: `${magicActionsRoot}/completion`,
    vision: `${magicActionsRoot}/vision`,
    transcription: `${magicActionsRoot}/transcribe`,
    status: `${magicActionsRoot}/status`,
} as const

export interface PollResult {
    data: unknown
}

function addContext(payload: Record<string, unknown>, context?: JobContext): Record<string, unknown> {
    if (!context) {
        return payload
    }

    return {
        ...payload,
        context_type: context.type,
        context_id: context.id,
        field_handle: context.field,
    }
}

function extractJobId(response: JobResponse): string {
    if (!response.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return response.job_id
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
    const payload = addContext({ text, action }, context)
    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.completion, payload)

    return { jobId: extractJobId(response.data) }
}

export async function executeVision(
    assetPath: string,
    action: string,
    variables: Record<string, unknown> = {},
    context?: JobContext,
): Promise<{ jobId: string }> {
    const payload = addContext(
        {
            asset_path: assetPath,
            action,
            variables,
        },
        context,
    )

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.vision, payload)

    return { jobId: extractJobId(response.data) }
}

export async function executeTranscription(
    assetPath: string,
    action: string,
    context?: JobContext,
): Promise<{ jobId: string }> {
    const payload = addContext(
        {
            asset_path: assetPath,
            action,
        },
        context,
    )

    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.transcription, payload)

    return { jobId: extractJobId(response.data) }
}
