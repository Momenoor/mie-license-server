<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<License>
 */
class LicenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plaintext = License::generateKey();

        return [
            'client_id' => Client::factory(),
            'key_hash' => License::hashKey($plaintext),
            'key_last_four' => substr($plaintext, -4),
            'product' => 'mie',
            'status' => 'active',
            'plan' => 'Standard',
            'max_activations' => 1,
            'issued_at' => now(),
            'expires_at' => null,
        ];
    }

    /**
     * Tests need the plaintext key to actually exercise the API with —
     * this fixes it to a known value instead of the random one
     * `definition()` otherwise generates, so the test can send it.
     */
    public function withKey(string $plaintext): static
    {
        return $this->state(fn (): array => [
            'key_hash' => License::hashKey($plaintext),
            'key_last_four' => substr($plaintext, -4),
        ]);
    }
}
