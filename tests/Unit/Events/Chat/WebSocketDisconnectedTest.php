<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\WebSocketDisconnected;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebSocketDisconnectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_can_be_instantiated(): void
    {
        $user = User::factory()->create(['name' => 'TestUser']);
        $event = new WebSocketDisconnected($user, 'conn-123');

        $this->assertInstanceOf(WebSocketDisconnected::class, $event);
        $this->assertSame($user, $event->user);
        $this->assertSame('conn-123', $event->connectionId);
    }

    public function test_event_accepts_null_connection_id(): void
    {
        $user = User::factory()->create();
        $event = new WebSocketDisconnected($user);

        $this->assertNull($event->connectionId);
    }

    public function test_broadcast_on_returns_websocket_disconnections_channel(): void
    {
        $user = User::factory()->create();
        $event = new WebSocketDisconnected($user);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('websocket-disconnections', $channels[0]->name);
    }

    public function test_broadcast_as_returns_websocket_disconnected(): void
    {
        $user = User::factory()->create();
        $event = new WebSocketDisconnected($user);

        $this->assertSame('websocket.disconnected', $event->broadcastAs());
    }

    public function test_broadcast_with_returns_user_and_connection_data(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        $event = new WebSocketDisconnected($user, 'conn-456');

        $data = $event->broadcastWith();

        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('user_name', $data);
        $this->assertArrayHasKey('connection_id', $data);
        $this->assertArrayHasKey('disconnected_at', $data);
        $this->assertSame($user->id, $data['user_id']);
        $this->assertSame('Alice', $data['user_name']);
        $this->assertSame('conn-456', $data['connection_id']);
    }
}
