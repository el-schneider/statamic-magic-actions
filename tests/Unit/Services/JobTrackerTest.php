<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Services\JobTracker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('stores job data on createJob and retrieves it with getJob', function () {
    $tracker = app(JobTracker::class);

    $tracker->createJob('job-1', 'propose-title', 'entry', 'entry-1', 'title');

    $job = $tracker->getJob('job-1');

    expect($job)
        ->not->toBeNull()
        ->and($job['status'])->toBe('queued')
        ->and($job['message'])->toBe('Job has been queued')
        ->and($job['context'])->toBe([
            'type' => 'entry',
            'id' => 'entry-1',
            'field' => 'title',
            'action' => 'propose-title',
        ])
        ->and($job)->toHaveKey('created_at');
});

it('updates job status message and data with updateStatus', function () {
    $tracker = app(JobTracker::class);

    $tracker->createJob('job-2', 'propose-title', 'entry', 'entry-2', 'title');
    $tracker->updateStatus('job-2', 'processing', 'Working on it');
    $tracker->updateStatus('job-2', 'completed', 'Done', ['title' => 'Generated title']);

    $job = $tracker->getJob('job-2');

    expect($job)
        ->not->toBeNull()
        ->and($job['status'])->toBe('completed')
        ->and($job['message'])->toBe('Done')
        ->and($job['data'])->toBe(['title' => 'Generated title'])
        ->and($job)->toHaveKey('updated_at');
});

it('returns null for unknown job ids', function () {
    $tracker = app(JobTracker::class);

    expect($tracker->getJob('missing-job'))->toBeNull();
});

it('creates a batch adds jobs and returns aggregate details', function () {
    $tracker = app(JobTracker::class);

    $batchId = $tracker->createBatch('propose-title', 2, ['source' => 'bulk-edit']);

    $tracker->createJob('batch-job-1', 'propose-title', 'entry', 'entry-1', 'title');
    $tracker->createJob('batch-job-2', 'propose-title', 'entry', 'entry-2', 'title');

    $tracker->addJobToBatch($batchId, 'batch-job-1');
    $tracker->addJobToBatch($batchId, 'batch-job-2');

    $batch = $tracker->getBatch($batchId);

    expect($batch)
        ->not->toBeNull()
        ->and($batch['batch_id'])->toBe($batchId)
        ->and($batch['action'])->toBe('propose-title')
        ->and($batch['total'])->toBe(2)
        ->and($batch['completed'])->toBe(0)
        ->and($batch['failed'])->toBe(0)
        ->and($batch['pending'])->toBe(2)
        ->and($batch['status'])->toBe('pending')
        ->and($batch['metadata'])->toBe(['source' => 'bulk-edit']);

    expect($tracker->getBatchJobs($batchId))->toBe(['batch-job-1', 'batch-job-2']);
});

it('resolves batch status for completed failed partial failure and processing states', function (array $statuses, string $expectedStatus) {
    $tracker = app(JobTracker::class);

    $batchId = $tracker->createBatch('propose-title', count($statuses));

    foreach ($statuses as $index => $status) {
        $jobId = 'status-job-'.($index + 1);

        $tracker->createJob($jobId, 'propose-title', 'entry', 'entry-'.($index + 1), 'title');
        $tracker->addJobToBatch($batchId, $jobId);
        $tracker->updateStatus($jobId, $status, ucfirst($status));
    }

    $batch = $tracker->getBatch($batchId);

    expect($batch)
        ->not->toBeNull()
        ->and($batch['status'])->toBe($expectedStatus);
})->with([
    'all completed' => [['completed', 'completed'], 'completed'],
    'all failed' => [['failed', 'failed'], 'failed'],
    'mixed completed and failed' => [['completed', 'failed'], 'partial_failure'],
    'some processing' => [['processing', 'queued'], 'processing'],
]);

it('returns an empty array for jobs of a non-existent batch', function () {
    $tracker = app(JobTracker::class);

    expect($tracker->getBatchJobs('missing-batch'))->toBe([]);
});
