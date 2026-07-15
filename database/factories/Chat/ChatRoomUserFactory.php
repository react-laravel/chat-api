<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatRoomUser>
 */
class ChatRoomUserFactory extends Factory
{
    /** @var class-string<ChatRoomUser> */
    protected $model = ChatRoomUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => ChatRoom::factory(),
            'user_id' => User::factory(),
            'joined_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'is_online' => $this->faker->boolean(70), // 70% chance of being online
            'is_muted' => false,
            'muted_until' => null,
            'is_banned' => false,
            'banned_until' => null,
            'muted_by' => null,
            'banned_by' => null,
        ];
    }

    /**
     * Indicate that the user is online.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online' => true,
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * Indicate that the user is offline.
     */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online' => false,
            'last_seen_at' => Carbon::now()->subMinutes($this->faker->numberBetween(5, 60)),
        ]);
    }

    /**
     * Indicate that the user is muted.
     */
    public function muted(?int $moderatorId = null, ?int $durationMinutes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_muted' => true,
            'muted_by' => $moderatorId ?? User::factory(),
            'muted_until' => $durationMinutes ? Carbon::now()->addMinutes($durationMinutes) : null,
        ]);
    }

    /**
     * Indicate that the user is banned.
     */
    public function banned(?int $moderatorId = null, ?int $durationMinutes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_banned' => true,
            'banned_by' => $moderatorId ?? User::factory(),
            'banned_until' => $durationMinutes ? Carbon::now()->addMinutes($durationMinutes) : null,
            'is_online' => false, // Banned users are automatically offline
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(int $minutesAgo = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online' => false,
            'last_seen_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);
    }
}
