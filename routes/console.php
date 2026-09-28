<?php

use App\Services\ReleasePublisher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('releases:sync-published {product?}', function (ReleasePublisher $publisher) {
    $changes = $publisher->sync($this->argument('product') ?? config('releases.product'));

    $this->info('Published: '.(implode(', ', $changes['published']) ?: 'none'));
    $this->info('Unpublished: '.(implode(', ', $changes['unpublished']) ?: 'none'));
})->purpose('Publish the newest release and unpublish older ones no installation runs');

// Catches installations that disappear without reporting in, e.g. with a
// deleted license.
Schedule::command('releases:sync-published')->daily();
