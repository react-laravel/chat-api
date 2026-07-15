<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\MessageSent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_sent_event_broadcasts_on_correct_channel()
    {
        // Arrange
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Act
        $event = new MessageSent($message);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertEquals("chat.room.{$room->id}", $channels[0]->name);
    }

    public function test_message_sent_event_has_correct_broadcast_name()
    {
        // Arrange
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Act
        $event = new MessageSent($message);

        // Assert
        $this->assertEquals('message.sent', $event->broadcastAs());
    }

    public function test_message_sent_event_broadcasts_correct_data()
    {
        // Arrange
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello, world!',
            'message_type' => 'text',
        ]);

        // Act
        $event = new MessageSent($message);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('message', $broadcastData);
        $messageData = $broadcastData['message'];

        $this->assertEquals($message->id, $messageData['id']);
        $this->assertEquals($room->id, $messageData['room_id']);
        $this->assertEquals($user->id, $messageData['user_id']);
        $this->assertEquals('Hello, world!', $messageData['message']);
        $this->assertEquals('text', $messageData['message_type']);
        $this->assertNotNull($messageData['created_at']);

        // Assert user data
        $this->assertArrayHasKey('user', $messageData);
        $userData = $messageData['user'];
        $this->assertEquals($user->id, $userData['id']);
        $this->assertEquals('John Doe', $userData['name']);
        $this->assertEquals('john@example.com', $userData['email']);
    }

    public function test_message_sent_event_serializes_message_model()
    {
        // Arrange
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Act
        $event = new MessageSent($message);

        // Assert
        $this->assertInstanceOf(ChatMessage::class, $event->message);
        $this->assertEquals($message->id, $event->message->id);
    }
}
