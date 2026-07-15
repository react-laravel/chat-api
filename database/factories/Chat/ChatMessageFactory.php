<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
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
            'message' => $this->faker->sentence(),
            'message_type' => ChatMessage::TYPE_TEXT,
        ];
    }

    /**
     * Indicate that the message is a text message.
     */
    public function textMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_type' => ChatMessage::TYPE_TEXT,
            'message' => $this->faker->sentence(),
        ]);
    }

    /**
     * Indicate that the message is a system message.
     */
    public function systemMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'message' => $this->faker->randomElement([
                'User joined the room',
                'User left the room',
                'Room was created',
                'Room settings updated',
            ]),
        ]);
    }
}
