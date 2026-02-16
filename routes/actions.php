<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Http\Controllers\ActionsController;
use Illuminate\Support\Facades\Route;

// Legacy compatibility aliases for one transition release.
// Prefer CP routes under /{cpRoot}/magic-actions/*.
Route::middleware('statamic.cp.authenticated')->group(function () {
    Route::post('completion', [ActionsController::class, 'completion'])->name('completion');
    Route::post('vision', [ActionsController::class, 'vision'])->name('vision');
    Route::post('transcribe', [ActionsController::class, 'transcribe'])->name('transcribe');

    Route::get('status/{jobId}', [ActionsController::class, 'status'])->name('status');

    Route::get('batch/{batchId}/status', [ActionsController::class, 'batchStatus'])->name('batch.status');
});
