import { pollJobStatus } from './api'
import type { JobContext } from './types'

interface TrackedJob {
    jobId: string
    fieldHandle: string
    fieldTitle: string
}

type UpdateFn = (value: unknown) => void

const STORAGE_KEY_PREFIX = 'magic_actions_jobs_'

const updateFunctions = new Map<string, UpdateFn>()

function getStorageKey(context: JobContext): string {
    return `${STORAGE_KEY_PREFIX}${context.type}_${context.id.replace(/[/:]/g, '_')}`
}

function getTrackedJobs(context: JobContext): TrackedJob[] {
    try {
        const key = getStorageKey(context)
        const stored = localStorage.getItem(key)
        return stored ? JSON.parse(stored) : []
    } catch {
        return []
    }
}

function saveTrackedJobs(context: JobContext, jobs: TrackedJob[]): void {
    const key = getStorageKey(context)
    if (jobs.length === 0) {
        localStorage.removeItem(key)
    } else {
        localStorage.setItem(key, JSON.stringify(jobs))
    }
}

function removeTrackedJob(context: JobContext, jobId: string): void {
    const jobs = getTrackedJobs(context).filter((j) => j.jobId !== jobId)
    saveTrackedJobs(context, jobs)
}

function updateFieldValue(jobId: string, value: string): void {
    const updateFn = updateFunctions.get(jobId)
    if (updateFn) {
        updateFn(value)
        updateFunctions.delete(jobId)
    }
}

export function startBackgroundJob(
    context: JobContext,
    jobId: string,
    fieldHandle: string,
    fieldTitle: string,
    update: UpdateFn,
): void {
    const jobs = getTrackedJobs(context)
    const job: TrackedJob = { jobId, fieldHandle, fieldTitle }

    if (!jobs.some((j) => j.jobId === jobId)) {
        jobs.push(job)
        saveTrackedJobs(context, jobs)
    }

    updateFunctions.set(jobId, update)
    pollInBackground(context, job)
}

async function pollInBackground(context: JobContext, job: TrackedJob): Promise<void> {
    try {
        const result = await pollJobStatus(job.jobId)
        updateFieldValue(job.jobId, result.data)
        window.Statamic.$toast.success(`"${job.fieldTitle}" completed!`)
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unknown error'
        window.Statamic.$toast.error(`"${job.fieldTitle}" failed: ${message}`)
        updateFunctions.delete(job.jobId)
    } finally {
        removeTrackedJob(context, job.jobId)
    }
}

export async function recoverTrackedJobs(context: JobContext): Promise<void> {
    const jobs = getTrackedJobs(context)

    if (jobs.length === 0) {
        return
    }

    for (const job of jobs) {
        window.Statamic.$toast.info(`"${job.fieldTitle}" is still processing...`)
        pollRecoveredJob(context, job)
    }
}

async function pollRecoveredJob(context: JobContext, job: TrackedJob): Promise<void> {
    try {
        await pollJobStatus(job.jobId)
        window.Statamic.$toast.info(`"${job.fieldTitle}" completed. Please save and refresh to see the result.`)
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unknown error'
        window.Statamic.$toast.error(`"${job.fieldTitle}" failed: ${message}`)
    } finally {
        removeTrackedJob(context, job.jobId)
    }
}
