<?php

use App\Services\SelfUpdater;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// The running self-update step's output so far, polled by the System Update
// page. A plain route: a Livewire call would queue behind the step itself.
Route::get('/admin/system-update/live-output', fn () => response()->json([
    'output' => app(SelfUpdater::class)->liveOutput(),
]))->middleware('auth')->name('self-update.live-output');

// The admin panel's logos (Admin -> Settings), served straight from storage
// so no public/storage symlink is needed. Public: the sign-in page shows it.
Route::get('/branding/{variant}', function (string $variant) {
    $path = Branding::path($variant === 'dark' ? Branding::LOGO_DARK : Branding::LOGO);

    abort_if($path === null, 404);

    return Storage::disk('public')->response($path, headers: ['Cache-Control' => 'public, max-age=86400']);
})->whereIn('variant', ['light', 'dark'])->name('branding.logo');
