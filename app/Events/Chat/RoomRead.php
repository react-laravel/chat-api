<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RoomRead implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $userId, public int $roomId, public int $messageId, public int $unreadCount) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId.'.notifications')];
    }

    public function broadcastAs(): string
    {
        return 'room.read';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId, 'last_read_message_id' => $this->messageId, 'unread_count' => $this->unreadCount];
    }
}
