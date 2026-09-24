<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin-editable key/value settings (Admin -> Settings), e.g. branding.
 * Read on every panel page, so they're cached — and an empty list is
 * never cached, so a read before anything is saved can't hide what's
 * saved next.
 */
class Setting extends Model
{
    public const CACHE_KEY = 'settings:all';

    protected $fillable = ['key', 'value'];

    /** @var array<string, string|null>|null */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::all_()[$key] ?? null;

        return filled($value) ? $value : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::clearCache();
    }

    public static function clearCache(): void
    {
        static::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private static function all_(): array
    {
        if (static::$memo !== null) {
            return static::$memo;
        }

        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && $cached !== []) {
                return static::$memo = $cached;
            }

            $settings = static::query()->pluck('value', 'key')->all();
        } catch (Throwable) {
            return []; // Not migrated yet (e.g. right after an update's checkout).
        }

        if ($settings !== []) {
            Cache::forever(self::CACHE_KEY, $settings);
        }

        return static::$memo = $settings;
    }
}
