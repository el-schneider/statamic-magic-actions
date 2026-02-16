<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Http\Controllers\ActionsController;
use ElSchneider\StatamicMagicActions\Http\Controllers\CP\SettingsController;
use Illuminate\Support\Facades\Route;

Route::name('magic-actions.')->prefix('magic-actions')->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');

    // AI endpoints (CP-native routes)
    Route::post('completion', [ActionsController::class, 'completion'])->name('ai.completion');
    Route::post('vision', [ActionsController::class, 'vision'])->name('ai.vision');
    Route::post('transcribe', [ActionsController::class, 'transcribe'])->name('ai.transcribe');
    Route::get('status/{jobId}', [ActionsController::class, 'status'])->name('ai.status');
    Route::get('batch/{batchId}/status', [ActionsController::class, 'batchStatus'])->name('ai.batch.status');
});
