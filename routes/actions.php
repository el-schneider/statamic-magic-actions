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

// Batch status endpoint
Route::get('batch/{batchId}/status', [ActionsController::class, 'batchStatus'])->name('batch.status');
