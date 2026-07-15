<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserUnbanned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $roomId;

    public int $userId;

    public int $moderatorId;

    public ?string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(int $roomId, int $userId, int $moderatorId, ?string $reason = null)
    {
        $this->roomId = $roomId;
        $this->userId = $userId;
        $this->moderatorId = $moderatorId;
        $this->reason = $reason;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("chat.room.{$this->roomId}"),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'moderator_id' => $this->moderatorId,
            'reason' => $this->reason,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user.unbanned';
    }
}
