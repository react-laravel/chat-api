<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\MessageDeleted;
use Illuminate\Broadcasting\Channel;
use Tests\TestCase;

class MessageDeletedTest extends TestCase
{
    public function test_message_deleted_event_broadcasts_on_correct_channel()
    {
        // Arrange
        $messageId = 123;
        $roomId = 456;
        $deletedBy = 789;

        // Act
        $event = new MessageDeleted($messageId, $roomId, $deletedBy);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertEquals("chat.room.{$roomId}", $channels[0]->name);
    }

    public function test_message_deleted_event_has_correct_broadcast_name()
    {
        // Arrange
        $messageId = 123;
        $roomId = 456;
        $deletedBy = 789;

        // Act
        $event = new MessageDeleted($messageId, $roomId, $deletedBy);

        // Assert
        $this->assertEquals('message.deleted', $event->broadcastAs());
    }

    public function test_message_deleted_event_broadcasts_correct_data()
    {
        // Arrange
        $messageId = 123;
        $roomId = 456;
        $deletedBy = 789;

        // Act
        $event = new MessageDeleted($messageId, $roomId, $deletedBy);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('message_id', $broadcastData);
        $this->assertArrayHasKey('room_id', $broadcastData);
        $this->assertArrayHasKey('deleted_by', $broadcastData);
        $this->assertArrayHasKey('deleted_at', $broadcastData);

        $this->assertEquals($messageId, $broadcastData['message_id']);
        $this->assertEquals($roomId, $broadcastData['room_id']);
        $this->assertEquals($deletedBy, $broadcastData['deleted_by']);
        $this->assertNotNull($broadcastData['deleted_at']);
    }

    public function test_message_deleted_event_stores_correct_properties()
    {
        // Arrange
        $messageId = 123;
        $roomId = 456;
        $deletedBy = 789;

        // Act
        $event = new MessageDeleted($messageId, $roomId, $deletedBy);

        // Assert
        $this->assertEquals($messageId, $event->messageId);
        $this->assertEquals($roomId, $event->roomId);
        $this->assertEquals($deletedBy, $event->deletedBy);
    }
}
