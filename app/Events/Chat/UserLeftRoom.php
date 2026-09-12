<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserLeftRoom implements ShouldBroadcast
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
        $channels = [new PrivateChannel('chat-room-'.$this->roomId)];
        if (! ChatRoom::whereKey($this->roomId)->where('is_private', true)->exists()) {
            $channels[] = new PrivateChannel('chat-rooms-list');
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user.left.room';
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
            'action' => 'left',
            'timestamp' => now()->toISOString(),
        ];
    }
}
