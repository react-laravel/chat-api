<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'is_admin' => false,
            'permissions' => [],
            'last_synced_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'is_admin' => true,
            'permissions' => ['admin', 'chat.moderate'],
        ]);
    }
}
