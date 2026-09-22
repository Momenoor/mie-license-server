<?php

use App\Http\Controllers\Api\LicenseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/license')->middleware('throttle:30,1')->group(function () {
    Route::post('activate', [LicenseController::class, 'activate']);
    Route::post('verify', [LicenseController::class, 'verify']);
});
