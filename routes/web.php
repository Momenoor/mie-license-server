<?php

use App\Services\SelfUpdater;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The running self-update step's output so far, polled by the System Update
// page. A plain route: a Livewire call would queue behind the step itself.
Route::get('/admin/system-update/live-output', fn () => response()->json([
    'output' => app(SelfUpdater::class)->liveOutput(),
]))->middleware('auth')->name('self-update.live-output');
