<?php

namespace Database\Factories;

use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        return [
            'product' => 'mie',
            'version' => $this->faker->unique()->numerify('1.#.#'),
            'notes' => $this->faker->sentence(),
            'is_published' => true,
            'released_at' => now(),
        ];
    }
}
