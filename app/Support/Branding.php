<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The admin panel's brand name, light/dark logos and favicon, set under
 * Admin -> Settings. Files are served by the `branding.logo` route
 * straight from storage, so no public/storage symlink is needed.
 */
class Branding
{
    public const NAME = 'brand_name';

    public const LOGO = 'brand_logo';

    public const LOGO_DARK = 'brand_logo_dark';

    public const FAVICON = 'brand_favicon';

    /** Setting key for each variant the `branding.logo` route serves. */
    public const VARIANTS = ['light' => self::LOGO, 'dark' => self::LOGO_DARK, 'favicon' => self::FAVICON];

    public const DIRECTORY = 'branding';

    public static function name(): string
    {
        return (string) Setting::get(self::NAME, config('app.name'));
    }

    /**
     * The light logo's URL, or null to show the brand name instead.
     */
    public static function logoUrl(): ?string
    {
        return static::url('light');
    }

    /**
     * The dark-mode logo, falling back to the light one.
     */
    public static function darkLogoUrl(): ?string
    {
        return static::url('dark') ?? static::logoUrl();
    }

    /**
     * The uploaded favicon, or null for the browser/Filament default.
     */
    public static function faviconUrl(): ?string
    {
        return static::url('favicon');
    }

    /**
     * The stored file for a setting key, if one exists.
     */
    public static function path(string $key): ?string
    {
        $path = Setting::get($key);

        return is_string($path) && Storage::disk('public')->exists($path) ? $path : null;
    }

    /**
     * Whether a path is one of the uploaded branding files — the only
     * files the `branding.file` preview route may serve.
     */
    public static function isBrandingFile(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY.'/')
            && ! str_contains($path, '..')
            && Storage::disk('public')->exists($path);
    }

    private static function url(string $variant): ?string
    {
        $path = static::path(self::VARIANTS[$variant]);

        // The file's own name busts browser caches when it's replaced.
        return $path !== null
            ? route('branding.logo', ['variant' => $variant, 'v' => basename($path)])
            : null;
    }
}
