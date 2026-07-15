<?php

namespace App\Events\Chat;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebSocketDisconnected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;

    public ?string $connectionId;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, ?string $connectionId = null)
    {
        $this->user = $user;
        $this->connectionId = $connectionId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('websocket-disconnections'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'websocket.disconnected';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'connection_id' => $this->connectionId,
            'disconnected_at' => now()->toISOString(),
        ];
    }
}
