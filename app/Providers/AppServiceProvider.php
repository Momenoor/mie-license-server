<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Storage stays UTC; every date column and picker in the admin
        // panel shows (and takes input in) local time.
        FilamentTimezone::set(config('app.display_timezone'));
    }
}
