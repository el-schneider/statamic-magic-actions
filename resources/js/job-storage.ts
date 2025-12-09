import type { JobContext, TrackedJob } from './types'

const STORAGE_KEY_PREFIX = 'magic_actions_jobs_'

function getStorageKey(context: JobContext): string {
    return `${STORAGE_KEY_PREFIX}${context.type}_${context.id.replace(/[/:]/g, '_')}`
}

export function getTrackedJobs(context: JobContext): TrackedJob[] {
    try {
        const key = getStorageKey(context)
        const stored = localStorage.getItem(key)
        return stored ? JSON.parse(stored) : []
    } catch {
        return []
    }
}

export function trackJob(job: TrackedJob): void {
    const jobs = getTrackedJobs(job.context)
    const existingIndex = jobs.findIndex((j) => j.jobId === job.jobId)

    if (existingIndex >= 0) {
        jobs[existingIndex] = job
    } else {
        jobs.push(job)
    }

    localStorage.setItem(getStorageKey(job.context), JSON.stringify(jobs))
}

export function removeTrackedJob(context: JobContext, jobId: string): void {
    const jobs = getTrackedJobs(context).filter((j) => j.jobId !== jobId)

    if (jobs.length === 0) {
        localStorage.removeItem(getStorageKey(context))
    } else {
        localStorage.setItem(getStorageKey(context), JSON.stringify(jobs))
    }
}
