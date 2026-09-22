<?php

namespace App\Models;

use Database\Factories\LicenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single issued license — the plaintext key is shown to the vendor
 * exactly once (see `generateKey()`/`LicenseResource`'s create flow) and
 * never stored; only `key_hash` persists, the same convention Laravel's
 * own API tokens use. Verifying a submitted key means re-hashing it and
 * comparing against this column.
 */
class License extends Model
{
    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    /**
     * Characters a human can reliably read/type back — no 0/O or 1/I.
     */
    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'client_id',
        'key_hash',
        'key_last_four',
        'product',
        'status',
        'plan',
        'max_activations',
        'issued_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'max_activations' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<LicenseActivation, $this>
     */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    /**
     * Generates a new plaintext key in the `MIE-XXXXX-XXXXX-XXXXX-XXXXX`
     * format — 20 characters from a human-typeable alphabet, grouped for
     * readability. The caller is responsible for hashing it into
     * `key_hash`/`key_last_four` before saving; this never touches the
     * database itself, since the plaintext must never be persisted.
     */
    public static function generateKey(): string
    {
        $alphabetLength = strlen(self::KEY_ALPHABET);
        $characters = '';

        for ($i = 0; $i < 20; $i++) {
            $characters .= self::KEY_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return 'MIE-'.implode('-', str_split($characters, 5));
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verify(string $plaintext): bool
    {
        return hash_equals($this->key_hash, self::hashKey($plaintext));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
