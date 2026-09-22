<?php

namespace App\Models;

use Database\Factories\LicenseActivationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    /** @use HasFactory<LicenseActivationFactory> */
    use HasFactory;

    protected $fillable = [
        'license_id',
        'fingerprint',
        'domain',
        'ip_address',
        'app_version',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
