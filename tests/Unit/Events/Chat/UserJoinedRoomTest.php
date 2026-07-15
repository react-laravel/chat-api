<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\UserJoinedRoom;
use Tests\TestCase;

class UserJoinedRoomTest extends TestCase
{
    public function test_event_can_be_instantiated(): void
    {
        $event = new UserJoinedRoom(5, 10, 'Alice', 4);

        $this->assertInstanceOf(UserJoinedRoom::class, $event);
        $this->assertSame(5, $event->roomId);
        $this->assertSame(10, $event->userId);
        $this->assertSame('Alice', $event->userName);
        $this->assertSame(4, $event->onlineCount);
    }

    public function test_broadcast_on_returns_room_and_list_channels(): void
    {
        $event = new UserJoinedRoom(7, 1, 'Bob', 3);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('chat-room-7', $channels[0]->name);
        $this->assertSame('chat-rooms-list', $channels[1]->name);
    }

    public function test_broadcast_as_returns_user_joined_room(): void
    {
        $event = new UserJoinedRoom(1, 1, 'Test', 0);

        $this->assertSame('user.joined.room', $event->broadcastAs());
    }

    public function test_broadcast_with_returns_room_data_with_action_joined(): void
    {
        $event = new UserJoinedRoom(3, 42, 'Charlie', 6);

        $data = $event->broadcastWith();

        $this->assertSame(3, $data['room_id']);
        $this->assertSame(42, $data['user_id']);
        $this->assertSame('Charlie', $data['user_name']);
        $this->assertSame(6, $data['online_count']);
        $this->assertSame('joined', $data['action']);
        $this->assertArrayHasKey('timestamp', $data);
    }
}
