<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The admin panel's brand name and light/dark logos, set under
 * Admin -> Settings. Logos are served by the `branding.logo` route
 * straight from storage, so no public/storage symlink is needed.
 */
class Branding
{
    public const NAME = 'brand_name';

    public const LOGO = 'brand_logo';

    public const LOGO_DARK = 'brand_logo_dark';

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
        return static::path(self::LOGO) !== null ? static::url('light') : null;
    }

    /**
     * The dark-mode logo, falling back to the light one.
     */
    public static function darkLogoUrl(): ?string
    {
        return static::path(self::LOGO_DARK) !== null ? static::url('dark') : static::logoUrl();
    }

    /**
     * The stored file for a variant ("light" or "dark"), if one exists.
     */
    public static function path(string $key): ?string
    {
        $path = Setting::get($key);

        return is_string($path) && Storage::disk('public')->exists($path) ? $path : null;
    }

    private static function url(string $variant): string
    {
        // The file's own name busts browser caches when a logo is replaced.
        $path = static::path($variant === 'dark' ? self::LOGO_DARK : self::LOGO);

        return route('branding.logo', ['variant' => $variant, 'v' => basename((string) $path)]);
    }
}
