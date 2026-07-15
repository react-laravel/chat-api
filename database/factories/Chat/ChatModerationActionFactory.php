<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatModerationAction>
 */
class ChatModerationActionFactory extends Factory
{
    /** @var class-string<ChatModerationAction> */
    protected $model = ChatModerationAction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => ChatRoom::factory(),
            'moderator_id' => User::factory(),
            'target_user_id' => User::factory(),
            'message_id' => ChatMessage::factory(),
            'action_type' => $this->faker->randomElement([
                ChatModerationAction::ACTION_DELETE_MESSAGE,
                ChatModerationAction::ACTION_MUTE_USER,
                ChatModerationAction::ACTION_UNMUTE_USER,
                ChatModerationAction::ACTION_TIMEOUT_USER,
                ChatModerationAction::ACTION_BAN_USER,
                ChatModerationAction::ACTION_UNBAN_USER,
            ]),
            'reason' => $this->faker->sentence(),
            'metadata' => [
                'auto_detected' => $this->faker->boolean(),
                'duration' => $this->faker->optional()->numberBetween(300, 86400),
            ],
        ];
    }

    /**
     * Indicate that the action is a delete message action.
     */
    public function deleteMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
        ]);
    }

    /**
     * Indicate that the action is a mute user action.
     */
    public function muteUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ChatModerationAction::ACTION_MUTE_USER,
            'metadata' => [
                'duration' => $this->faker->numberBetween(300, 86400),
            ],
        ]);
    }

    /**
     * Indicate that the action is a ban user action.
     */
    public function banUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ChatModerationAction::ACTION_BAN_USER,
        ]);
    }

    /**
     * Indicate that the action is automated (using available action types).
     */
    public function automated(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => $this->faker->randomElement([
                ChatModerationAction::ACTION_DELETE_MESSAGE,
                ChatModerationAction::ACTION_MUTE_USER,
            ]),
            'metadata' => [
                'auto_detected' => true,
                'confidence' => $this->faker->randomFloat(2, 0.5, 1.0),
            ],
        ]);
    }
}
