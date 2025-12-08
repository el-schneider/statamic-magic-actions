import { sleep } from './helpers'
import type { JobResponse, JobStatusResponse } from './types'

const ENDPOINTS = {
    completion: '/!/statamic-magic-actions/completion',
    vision: '/!/statamic-magic-actions/vision',
    transcription: '/!/statamic-magic-actions/transcribe',
    status: '/!/statamic-magic-actions/status',
} as const

async function pollJobStatus(jobId: string, maxAttempts = 60, intervalMs = 1000): Promise {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const response = await window.Statamic.$axios.get<JobStatusResponse>(`${ENDPOINTS.status}/${jobId}`)
        const { status, data, error } = response.data

        if (status === 'completed') {
            return data!
        }

        if (status === 'failed') {
            throw new Error(error || 'Job failed')
        }

        await sleep(intervalMs)
    }

    throw new Error('Timed out waiting for job to complete')
}

export async function executeCompletion(text: string, action: string): Promise {
    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.completion, {
        text,
        action,
    })

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return pollJobStatus(response.data.job_id)
}

export async function executeVision(assetPath: string, action: string, variables: Record = {}): Promise {
    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.vision, {
        asset_path: assetPath,
        action,
        variables,
    })

    if (!response.data.job_id) {
        throw new Error('No job ID returned from the server')
    }

    return pollJobStatus(response.data.job_id)
}

export async function executeTranscription(assetPath: string, action: string): Promise {
    const response = await window.Statamic.$axios.post<JobResponse>(ENDPOINTS.transcription, {
        asset_path: assetPath,
        action,
    })

    return pollJobStatus(response.data.job_id)
}
