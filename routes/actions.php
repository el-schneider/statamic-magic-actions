<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Http\Controllers\ActionsController;
use Illuminate\Support\Facades\Route;

// Job submission endpoints
Route::post('completion', [ActionsController::class, 'completion'])->name('completion');
Route::post('vision', [ActionsController::class, 'vision'])->name('vision');
Route::post('transcribe', [ActionsController::class, 'transcribe'])->name('transcribe');

// Job status endpoint
Route::get('status/{jobId}', [ActionsController::class, 'status'])->name('status');

// Job management endpoints for background processing recovery
Route::get('jobs/{contextType}/{contextId}', [ActionsController::class, 'jobs'])
    ->name('jobs')
    ->where('contextId', '.*');
Route::post('acknowledge/{jobId}', [ActionsController::class, 'acknowledge'])->name('acknowledge');
Route::post('dismiss/{jobId}', [ActionsController::class, 'dismiss'])->name('dismiss');
