<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\ReleaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/license')->middleware('throttle:30,1')->group(function () {
    Route::post('activate', [LicenseController::class, 'activate']);
    Route::post('verify', [LicenseController::class, 'verify']);
});

// The app repository's GitHub Action, on every vX.Y.Z tag.
Route::post('v1/releases', [ReleaseController::class, 'store'])->middleware('throttle:30,1');
