<?php

declare(strict_types=1);

use ElSchneider\StatamicMagicActions\Http\Controllers\CP\SettingsController;
use Illuminate\Support\Facades\Route;

Route::name('magic-actions.')->prefix('magic-actions')->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
});
