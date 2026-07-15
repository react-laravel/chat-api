<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserJoinedRoom implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomId;

    public $userId;

    public $userName;

    public $onlineCount;

    /**
     * Create a new event instance.
     */
    public function __construct(int $roomId, int $userId, string $userName, int $onlineCount)
    {
        $this->roomId = $roomId;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->onlineCount = $onlineCount;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('chat-room-'.$this->roomId),
            new Channel('chat-rooms-list'), // 广播到房间列表频道
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user.joined.room';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'online_count' => $this->onlineCount,
            'action' => 'joined',
            'timestamp' => now()->toISOString(),
        ];
    }
}
