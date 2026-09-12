<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatRoom $room;

    public string $message;

    public string $type;

    public ?User $triggeredBy;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatRoom $room, string $message, string $type = 'info', ?User $triggeredBy = null)
    {
        $this->room = $room;
        $this->message = $message;
        $this->type = $type;
        $this->triggeredBy = $triggeredBy;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.room.{$this->room->id}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'room.notification';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $data = [
            'notification' => [
                'id' => uniqid('room_'),
                'type' => $this->type,
                'message' => $this->message,
                'room' => [
                    'id' => $this->room->id,
                    'name' => $this->room->name,
                    'description' => $this->room->description,
                ],
                'created_at' => now()->toISOString(),
            ],
        ];

        if ($this->triggeredBy) {
            $data['notification']['triggered_by'] = [
                'id' => $this->triggeredBy->id,
                'name' => $this->triggeredBy->name,
                'email' => $this->triggeredBy->email,
            ];
        }

        return $data;
    }

    /**
     * Determine if this event should be queued.
     */
    public function shouldQueue(): bool
    {
        return true;
    }
}
