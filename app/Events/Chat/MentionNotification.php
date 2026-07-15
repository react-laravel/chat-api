<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MentionNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatMessage $message;

    public User $mentionedUser;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatMessage $message, User $mentionedUser)
    {
        $this->message = $message;
        $this->mentionedUser = $mentionedUser;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->mentionedUser->id}.notifications"),
            new Channel("chat.room.{$this->message->room_id}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'mention.notification';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id' => uniqid('mention_'),
                'type' => 'mention',
                'message' => [
                    'id' => $this->message->id,
                    'room_id' => $this->message->room_id,
                    'message' => $this->message->message,
                    'created_at' => $this->message->created_at->toISOString(),
                    'user' => [
                        'id' => $this->message->user->id,
                        'name' => $this->message->user->name,
                        'email' => $this->message->user->email,
                    ],
                ],
                'mentioned_user' => [
                    'id' => $this->mentionedUser->id,
                    'name' => $this->mentionedUser->name,
                    'email' => $this->mentionedUser->email,
                ],
                'created_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Determine if this event should be queued.
     */
    public function shouldQueue(): bool
    {
        return true;
    }
}
