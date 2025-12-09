import { pollJobStatus } from './api'
import type { JobContext } from './types'

interface TrackedJob {
    jobId: string
    fieldHandle: string
    fieldTitle: string
}

interface StoreRef {
    store: Window['Statamic']['Store']['store']
    storeName: string
}

const STORAGE_KEY_PREFIX = 'magic_actions_jobs_'

let activeStore: StoreRef | null = null

export function setActiveStore(store: StoreRef['store'], storeName: string): void {
    activeStore = { store, storeName }
}

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

function updateFieldValue(fieldHandle: string, value: string): void {
    if (!activeStore) {
        return
    }

    const state = activeStore.store.state.publish[activeStore.storeName]
    if (state?.values && Object.prototype.hasOwnProperty.call(state.values, fieldHandle)) {
        state.values[fieldHandle] = value
    }
}

export function startBackgroundJob(context: JobContext, jobId: string, fieldHandle: string, fieldTitle: string): void {
    const jobs = getTrackedJobs(context)
    const job: TrackedJob = { jobId, fieldHandle, fieldTitle }

    if (!jobs.some((j) => j.jobId === jobId)) {
        jobs.push(job)
        saveTrackedJobs(context, jobs)
    }

    pollInBackground(context, job)
}

async function pollInBackground(context: JobContext, job: TrackedJob): Promise {
    try {
        const result = await pollJobStatus(job.jobId)
        updateFieldValue(job.fieldHandle, result.data)
        window.Statamic.$toast.success(`"${job.fieldTitle}" completed!`)
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unknown error'
        window.Statamic.$toast.error(`"${job.fieldTitle}" failed: ${message}`)
    } finally {
        removeTrackedJob(context, job.jobId)
    }
}

export async function recoverTrackedJobs(context: JobContext): Promise {
    const jobs = getTrackedJobs(context)

    if (jobs.length === 0) {
        return
    }

    for (const job of jobs) {
        window.Statamic.$toast.info(`"${job.fieldTitle}" is still processing...`)
        pollInBackground(context, job)
    }
}
