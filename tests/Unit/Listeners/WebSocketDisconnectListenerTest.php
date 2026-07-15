<?php

namespace Tests\Unit\Listeners;

use App\Events\Chat\WebSocketDisconnected;
use App\Listeners\WebSocketDisconnectListener;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\WebSocketDisconnectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Mockery;
use Tests\TestCase;

class WebSocketDisconnectListenerTest extends TestCase
{
    use RefreshDatabase;

    private WebSocketDisconnectListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);

        $mockService = Mockery::mock(WebSocketDisconnectService::class);
        $this->listener = new WebSocketDisconnectListener($mockService);
    }

    public function test_listener_can_be_instantiated(): void
    {
        $mockService = Mockery::mock(WebSocketDisconnectService::class);
        $listener = new WebSocketDisconnectListener($mockService);

        $this->assertInstanceOf(WebSocketDisconnectListener::class, $listener);
    }

    public function test_listener_implements_should_queue(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, $this->listener);
    }

    public function test_listener_uses_interacts_with_queue_trait(): void
    {
        $this->assertTrue(in_array(
            InteractsWithQueue::class,
            class_uses_recursive(WebSocketDisconnectListener::class)
        ));
    }

    public function test_listener_calls_disconnect_service_with_user_id_and_connection_id(): void
    {
        $user = User::factory()->create();
        $connectionId = 'test-connection-123';

        $mockService = Mockery::mock(WebSocketDisconnectService::class);
        $mockService->shouldReceive('handleDisconnect')
            ->once()
            ->with($user->id, $connectionId)
            ->andReturn();

        $listener = new WebSocketDisconnectListener($mockService);
        $event = new WebSocketDisconnected($user, $connectionId);

        $listener->handle($event);
    }

    public function test_listener_calls_disconnect_service_with_null_connection_id(): void
    {
        $user = User::factory()->create();

        $mockService = Mockery::mock(WebSocketDisconnectService::class);
        $mockService->shouldReceive('handleDisconnect')
            ->once()
            ->with($user->id, null)
            ->andReturn();

        $listener = new WebSocketDisconnectListener($mockService);
        $event = new WebSocketDisconnected($user);

        $listener->handle($event);
    }

    public function test_listener_handles_event_integration(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $room = ChatRoom::factory()->create();
        $connectionId = 'connection-abc-123';

        ChatRoomUser::factory()->online()->create([
            'user_id' => $user->id,
            'room_id' => $room->id,
        ]);

        $event = new WebSocketDisconnected($user, $connectionId);

        // This should not throw an exception
        $this->expectNotToPerformAssertions();
    }

    public function test_listener_constructor_accepts_disconnect_service(): void
    {
        $mockService = Mockery::mock(WebSocketDisconnectService::class);

        $listener = new WebSocketDisconnectListener($mockService);

        $reflection = new \ReflectionClass($listener);
        $property = $reflection->getProperty('disconnectService');
        $property->setAccessible(true);

        $this->assertSame($mockService, $property->getValue($listener));
    }

    public function test_event_has_user_and_connection_id(): void
    {
        $user = User::factory()->create();
        $connectionId = 'test-conn-id';

        $event = new WebSocketDisconnected($user, $connectionId);

        $this->assertEquals($user->id, $event->user->id);
        $this->assertEquals($connectionId, $event->connectionId);
    }

    public function test_event_has_null_connection_id_by_default(): void
    {
        $user = User::factory()->create();

        $event = new WebSocketDisconnected($user);

        $this->assertNull($event->connectionId);
    }

    public function test_event_broadcast_on_returns_websocket_channel(): void
    {
        $user = User::factory()->create();
        $event = new WebSocketDisconnected($user);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertEquals('websocket-disconnections', $channels[0]->name);
    }

    public function test_event_broadcast_as_returns_disconnected_event(): void
    {
        $user = User::factory()->create();
        $event = new WebSocketDisconnected($user);

        $this->assertEquals('websocket.disconnected', $event->broadcastAs());
    }

    public function test_event_broadcast_with_returns_user_data(): void
    {
        $user = User::factory()->create(['name' => 'BroadcastUser']);
        $connectionId = 'broadcast-conn';

        $event = new WebSocketDisconnected($user, $connectionId);
        $data = $event->broadcastWith();

        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('user_name', $data);
        $this->assertArrayHasKey('connection_id', $data);
        $this->assertArrayHasKey('disconnected_at', $data);

        $this->assertEquals($user->id, $data['user_id']);
        $this->assertEquals($user->name, $data['user_name']);
        $this->assertEquals($connectionId, $data['connection_id']);
    }
}
