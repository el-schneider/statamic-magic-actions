import { acknowledgeJob, dismissJob, getRecoverableJobs, pollJobStatus } from './api'
import { getTrackedJobs, removeTrackedJob } from './job-storage'
import type { JobContext, MagicField, TrackedJob } from './types'

async function pollJobInBackground(tracked: TrackedJob, context: JobContext): Promise {
    try {
        const result = await pollJobStatus(tracked.jobId)

        if (result.status === 'completed') {
            window.Statamic.$toast.success(`Magic action for "${tracked.fieldHandle}" completed!`)
            await acknowledgeJob(tracked.jobId)
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unknown error'
        window.Statamic.$toast.error(`Background job failed: ${message}`)
        await dismissJob(tracked.jobId)
    } finally {
        removeTrackedJob(context, tracked.jobId)
    }
}

export async function recoverBackgroundJobs(
    context: JobContext,
    store: Window['Statamic']['Store']['store'],
    storeName: string,
    magicFields: MagicField[],
): Promise {
    const trackedJobs = getTrackedJobs(context)

    if (trackedJobs.length === 0) {
        return
    }

    const serverJobs = await getRecoverableJobs(context)
    const serverJobMap = new Map(serverJobs.map((j) => [j.job_id, j]))

    for (const tracked of trackedJobs) {
        const serverJob = serverJobMap.get(tracked.jobId)

        if (!serverJob) {
            removeTrackedJob(context, tracked.jobId)
            continue
        }

        const fieldContext: JobContext = { ...context, field: tracked.fieldHandle }

        if (serverJob.status === 'completed' && serverJob.data) {
            const fieldConfig = magicFields.find((f) => f.action === tracked.action)
            const fieldTitle = fieldConfig?.title || tracked.action

            window.Statamic.$toast.info(`Magic action "${fieldTitle}" completed in background`)

            try {
                const state = store.state.publish[storeName]
                if (state?.values && Object.prototype.hasOwnProperty.call(state.values, tracked.fieldHandle)) {
                    state.values[tracked.fieldHandle] = serverJob.data
                    window.Statamic.$toast.success(`Applied result to "${tracked.fieldHandle}" field`)
                }

                await acknowledgeJob(tracked.jobId)
            } catch (error) {
                console.error('Error applying recovered job result:', error)
            }

            removeTrackedJob(fieldContext, tracked.jobId)
        } else if (serverJob.status === 'failed') {
            window.Statamic.$toast.error(`Background job for "${tracked.fieldHandle}" failed: ${serverJob.error}`)
            await dismissJob(tracked.jobId)
            removeTrackedJob(fieldContext, tracked.jobId)
        } else if (serverJob.status === 'queued' || serverJob.status === 'processing') {
            window.Statamic.$toast.info(`Magic action for "${tracked.fieldHandle}" is still processing...`)
            pollJobInBackground(tracked, fieldContext)
        }
    }
}
