<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatRoomUser;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RoomActivity implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $roomId) {}

    public function broadcastOn(): array
    {
        return ChatRoomUser::where('room_id', $this->roomId)
            ->where(fn ($query) => $query->where('is_banned', false)->orWhere('banned_until', '<=', now()))
            ->pluck('user_id')->map(fn ($id) => new PrivateChannel('user.'.$id.'.notifications'))->all();
    }

    public function broadcastAs(): string
    {
        return 'room.activity';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId];
    }
}
