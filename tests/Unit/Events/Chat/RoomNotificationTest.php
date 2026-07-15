<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\RoomNotification;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_notification_event_broadcasts_on_correct_channel()
    {
        // Arrange
        $room = ChatRoom::factory()->create();
        $message = 'Welcome to the chat room!';

        // Act
        $event = new RoomNotification($room, $message);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertEquals("chat.room.{$room->id}", $channels[0]->name);
    }

    public function test_room_notification_event_has_correct_broadcast_name()
    {
        // Arrange
        $room = ChatRoom::factory()->create();
        $message = 'Welcome to the chat room!';

        // Act
        $event = new RoomNotification($room, $message);

        // Assert
        $this->assertEquals('room.notification', $event->broadcastAs());
    }

    public function test_room_notification_event_broadcasts_correct_data_without_triggered_by()
    {
        // Arrange
        $room = ChatRoom::factory()->create([
            'name' => 'General Chat',
            'description' => 'General discussion room',
        ]);
        $message = 'Welcome to the chat room!';
        $type = 'info';

        // Act
        $event = new RoomNotification($room, $message, $type);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('notification', $broadcastData);
        $notification = $broadcastData['notification'];

        $this->assertArrayHasKey('id', $notification);
        $this->assertEquals($type, $notification['type']);
        $this->assertEquals($message, $notification['message']);
        $this->assertArrayHasKey('room', $notification);
        $this->assertArrayHasKey('created_at', $notification);
        $this->assertArrayNotHasKey('triggered_by', $notification);

        // Assert room data
        $roomData = $notification['room'];
        $this->assertEquals($room->id, $roomData['id']);
        $this->assertEquals('General Chat', $roomData['name']);
        $this->assertEquals('General discussion room', $roomData['description']);
    }

    public function test_room_notification_event_broadcasts_correct_data_with_triggered_by()
    {
        // Arrange
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $room = ChatRoom::factory()->create();
        $message = 'Room settings have been updated';
        $type = 'warning';

        // Act
        $event = new RoomNotification($room, $message, $type, $user);
        $broadcastData = $event->broadcastWith();

        // Assert
        $notification = $broadcastData['notification'];
        $this->assertEquals($type, $notification['type']);
        $this->assertEquals($message, $notification['message']);
        $this->assertArrayHasKey('triggered_by', $notification);

        // Assert triggered_by data
        $triggeredByData = $notification['triggered_by'];
        $this->assertEquals($user->id, $triggeredByData['id']);
        $this->assertEquals('John Doe', $triggeredByData['name']);
        $this->assertEquals('john@example.com', $triggeredByData['email']);
    }

    public function test_room_notification_event_should_be_queued()
    {
        // Arrange
        $room = ChatRoom::factory()->create();
        $message = 'Welcome to the chat room!';

        // Act
        $event = new RoomNotification($room, $message);

        // Assert
        $this->assertTrue($event->shouldQueue());
    }

    public function test_room_notification_event_stores_correct_properties()
    {
        // Arrange
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = 'Test message';
        $type = 'error';

        // Act
        $event = new RoomNotification($room, $message, $type, $user);

        // Assert
        $this->assertInstanceOf(ChatRoom::class, $event->room);
        $this->assertEquals($room->id, $event->room->id);
        $this->assertEquals($message, $event->message);
        $this->assertEquals($type, $event->type);
        $this->assertInstanceOf(User::class, $event->triggeredBy);
        $this->assertEquals($user->id, $event->triggeredBy->id);
    }

    public function test_room_notification_event_with_default_type()
    {
        // Arrange
        $room = ChatRoom::factory()->create();
        $message = 'Default notification';

        // Act
        $event = new RoomNotification($room, $message);

        // Assert
        $this->assertEquals('info', $event->type);
        $this->assertNull($event->triggeredBy);
    }
}
