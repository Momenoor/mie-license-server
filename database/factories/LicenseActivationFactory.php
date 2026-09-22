<?php

namespace Database\Factories;

use App\Models\License;
use App\Models\LicenseActivation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseActivation>
 */
class LicenseActivationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'license_id' => License::factory(),
            'fingerprint' => $this->faker->uuid(),
            'domain' => $this->faker->domainName(),
            'ip_address' => $this->faker->ipv4(),
            'app_version' => '1.0.0',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
