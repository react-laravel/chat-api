<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\MentionNotification;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mention_notification_event_broadcasts_on_correct_channels()
    {
        // Arrange
        $sender = User::factory()->create();
        $mentionedUser = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
        ]);

        // Act
        $event = new MentionNotification($message, $mentionedUser);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertInstanceOf(Channel::class, $channels[1]);
        $this->assertEquals("private-user.{$mentionedUser->id}.notifications", $channels[0]->name);
        $this->assertEquals("chat.room.{$room->id}", $channels[1]->name);
    }

    public function test_mention_notification_event_has_correct_broadcast_name()
    {
        // Arrange
        $sender = User::factory()->create();
        $mentionedUser = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
        ]);

        // Act
        $event = new MentionNotification($message, $mentionedUser);

        // Assert
        $this->assertEquals('mention.notification', $event->broadcastAs());
    }

    public function test_mention_notification_event_broadcasts_correct_data()
    {
        // Arrange
        $sender = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $mentionedUser = User::factory()->create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
            'message' => 'Hello @jane, how are you?',
        ]);

        // Act
        $event = new MentionNotification($message, $mentionedUser);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('notification', $broadcastData);
        $notification = $broadcastData['notification'];

        $this->assertArrayHasKey('id', $notification);
        $this->assertEquals('mention', $notification['type']);
        $this->assertArrayHasKey('message', $notification);
        $this->assertArrayHasKey('mentioned_user', $notification);
        $this->assertArrayHasKey('created_at', $notification);

        // Assert message data
        $messageData = $notification['message'];
        $this->assertEquals($message->id, $messageData['id']);
        $this->assertEquals($room->id, $messageData['room_id']);
        $this->assertEquals('Hello @jane, how are you?', $messageData['message']);
        $this->assertNotNull($messageData['created_at']);

        // Assert sender data
        $senderData = $messageData['user'];
        $this->assertEquals($sender->id, $senderData['id']);
        $this->assertEquals('John Doe', $senderData['name']);
        $this->assertEquals('john@example.com', $senderData['email']);

        // Assert mentioned user data
        $mentionedUserData = $notification['mentioned_user'];
        $this->assertEquals($mentionedUser->id, $mentionedUserData['id']);
        $this->assertEquals('Jane Smith', $mentionedUserData['name']);
        $this->assertEquals('jane@example.com', $mentionedUserData['email']);
    }

    public function test_mention_notification_event_should_be_queued()
    {
        // Arrange
        $sender = User::factory()->create();
        $mentionedUser = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
        ]);

        // Act
        $event = new MentionNotification($message, $mentionedUser);

        // Assert
        $this->assertTrue($event->shouldQueue());
    }

    public function test_mention_notification_event_stores_correct_properties()
    {
        // Arrange
        $sender = User::factory()->create();
        $mentionedUser = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
        ]);

        // Act
        $event = new MentionNotification($message, $mentionedUser);

        // Assert
        $this->assertInstanceOf(ChatMessage::class, $event->message);
        $this->assertInstanceOf(User::class, $event->mentionedUser);
        $this->assertEquals($message->id, $event->message->id);
        $this->assertEquals($mentionedUser->id, $event->mentionedUser->id);
    }
}
