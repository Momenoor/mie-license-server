<?php

namespace App\Models;

use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A published version of a product. Installations learn about the newest
 * one through the activate/verify responses, and their one-click updater
 * checks out git tag `v{version}` — so a release is only published once
 * that tag exists on the repository.
 */
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    protected $fillable = [
        'product',
        'version',
        'notes',
        'is_published',
        'released_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'released_at' => 'datetime',
    ];

    /**
     * The highest published version for a product — by semantic version,
     * not by date or string order ("1.10.0" is newer than "1.9.0").
     */
    public static function latestFor(string $product): ?self
    {
        return static::query()
            ->where('product', $product)
            ->where('is_published', true)
            ->get()
            ->sort(fn (self $a, self $b): int => version_compare($b->version, $a->version))
            ->first();
    }
}
