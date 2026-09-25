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
    'silent_for' => app(SelfUpdater::class)->secondsSinceOutput(),
]))->middleware('auth')->name('self-update.live-output');

// The Settings page's upload previews: any stored branding file by path.
// Signed-in only, and never anything outside the branding folder.
Route::get('/branding/file/{path}', function (string $path) {
    abort_unless(Branding::isBrandingFile($path), 404);

    return Storage::disk('public')->response($path);
})->where('path', '.*')->middleware('auth')->name('branding.file');

// The admin panel's logos and favicon (Admin -> Settings), served straight
// from storage so no public/storage symlink is needed. Public: the sign-in
// page shows them.
Route::get('/branding/{variant}', function (string $variant) {
    $path = Branding::path(Branding::VARIANTS[$variant]);

    abort_if($path === null, 404);

    return Storage::disk('public')->response($path, headers: ['Cache-Control' => 'public, max-age=86400']);
})->whereIn('variant', array_keys(Branding::VARIANTS))->name('branding.logo');
