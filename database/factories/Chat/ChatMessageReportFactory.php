<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReport;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessageReport>
 */
class ChatMessageReportFactory extends Factory
{
    /** @var class-string<ChatMessageReport> */
    protected $model = ChatMessageReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => ChatMessage::factory(),
            'reported_by' => User::factory(),
            'room_id' => ChatRoom::factory(),
            'report_type' => $this->faker->randomElement([
                ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
                ChatMessageReport::TYPE_SPAM,
                ChatMessageReport::TYPE_HARASSMENT,
                ChatMessageReport::TYPE_HATE_SPEECH,
                ChatMessageReport::TYPE_VIOLENCE,
                ChatMessageReport::TYPE_SEXUAL_CONTENT,
                ChatMessageReport::TYPE_MISINFORMATION,
                ChatMessageReport::TYPE_OTHER,
            ]),
            'reason' => $this->faker->sentence(),
            'status' => ChatMessageReport::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'metadata' => [
                'auto_detected' => $this->faker->boolean(),
                'confidence' => $this->faker->optional()->randomFloat(2, 0.5, 1.0),
            ],
        ];
    }

    /**
     * Indicate that the report is for inappropriate content.
     */
    public function inappropriateContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
        ]);
    }

    /**
     * Indicate that the report is for spam.
     */
    public function spam(): static
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);
    }

    /**
     * Indicate that the report is for harassment.
     */
    public function harassment(): static
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
        ]);
    }

    /**
     * Indicate that the report is for hate speech.
     */
    public function hateSpeech(): static
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => ChatMessageReport::TYPE_HATE_SPEECH,
        ]);
    }

    /**
     * Indicate that the report is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatMessageReport::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ]);
    }

    /**
     * Indicate that the report has been reviewed.
     */
    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatMessageReport::STATUS_REVIEWED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'review_notes' => $this->faker->optional()->sentence(),
        ]);
    }

    /**
     * Indicate that the report has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatMessageReport::STATUS_RESOLVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'review_notes' => $this->faker->optional()->sentence(),
        ]);
    }

    /**
     * Indicate that the report has been dismissed.
     */
    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatMessageReport::STATUS_DISMISSED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'review_notes' => $this->faker->optional()->sentence(),
        ]);
    }

    /**
     * Indicate that the report was auto-detected.
     */
    public function autoDetected(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => [
                'auto_detected' => true,
                'confidence' => $this->faker->randomFloat(2, 0.7, 1.0),
                'detection_method' => $this->faker->randomElement(['ai', 'rule_based', 'pattern_matching']),
            ],
        ]);
    }
}
