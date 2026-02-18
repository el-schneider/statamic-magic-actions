import { pollJobStatus } from './api'
import type { JobContext, RelationshipMetaSyncContext } from './types'

interface TrackedJob {
    jobId: string
    fieldHandle: string
    fieldTitle: string
}

type UpdateFn = (value: unknown) => void

interface UpdateContext {
    update: UpdateFn
    relationshipMetaSync?: RelationshipMetaSyncContext
}

const STORAGE_KEY_PREFIX = 'magic_actions_jobs_'

const updateFunctions = new Map<string, UpdateContext>()

function isTrackedJob(value: unknown): value is TrackedJob {
    if (!value || typeof value !== 'object') {
        return false
    }

    const job = value as Partial<TrackedJob>

    return typeof job.jobId === 'string' && typeof job.fieldHandle === 'string' && typeof job.fieldTitle === 'string'
}

function getStorageKey(context: JobContext): string {
    return `${STORAGE_KEY_PREFIX}${context.type}_${context.id.replace(/[/:]/g, '_')}`
}

function getTrackedJobs(context: JobContext): TrackedJob[] {
    try {
        const key = getStorageKey(context)
        const stored = localStorage.getItem(key)
        const parsed = stored ? JSON.parse(stored) : []

        return Array.isArray(parsed) ? parsed.filter(isTrackedJob) : []
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

async function syncRelationshipMeta(
    relationshipMetaSync: RelationshipMetaSyncContext | undefined,
    value: unknown,
): Promise<void> {
    if (!relationshipMetaSync || !Array.isArray(value)) {
        return
    }

    try {
        const response = await window.Statamic.$axios.post<{ data: unknown[] }>(relationshipMetaSync.itemDataUrl, {
            site: relationshipMetaSync.site,
            selections: value,
        })

        const latestMeta = relationshipMetaSync.vm?.meta ?? relationshipMetaSync.meta

        relationshipMetaSync.updateMeta({
            ...latestMeta,
            data: response.data.data,
        })
    } catch {
        // Keep field value update successful even if relationship meta sync fails.
    }
}

async function updateFieldValue(jobId: string, value: unknown): Promise<void> {
    const updateContext = updateFunctions.get(jobId)
    if (updateContext) {
        updateContext.update(value)
        updateFunctions.delete(jobId)
        await syncRelationshipMeta(updateContext.relationshipMetaSync, value)
    }
}

export function startBackgroundJob(
    context: JobContext,
    jobId: string,
    fieldHandle: string,
    fieldTitle: string,
    update: UpdateFn,
    relationshipMetaSync?: RelationshipMetaSyncContext,
): void {
    const jobs = getTrackedJobs(context)
    const job: TrackedJob = { jobId, fieldHandle, fieldTitle }

    if (!jobs.some((j) => j.jobId === jobId)) {
        jobs.push(job)
        saveTrackedJobs(context, jobs)
    }

    updateFunctions.set(jobId, {
        update,
        relationshipMetaSync,
    })
    pollInBackground(context, job)
}

async function pollInBackground(context: JobContext, job: TrackedJob): Promise<void> {
    try {
        const result = await pollJobStatus(job.jobId)
        await updateFieldValue(job.jobId, result.data)
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
