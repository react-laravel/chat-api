<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $messageId;

    public int $roomId;

    public int $deletedBy;

    public ?string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(int $messageId, int $roomId, int $deletedBy, ?string $reason = null)
    {
        $this->messageId = $messageId;
        $this->roomId = $roomId;
        $this->deletedBy = $deletedBy;
        $this->reason = $reason;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("chat.room.{$this->roomId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'room_id' => $this->roomId,
            'deleted_by' => $this->deletedBy,
            'reason' => $this->reason,
            'deleted_at' => now()->toISOString(),
        ];
    }
}
