<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\UserJoined;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserJoinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_joined_event_broadcasts_on_correct_channels()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;

        // Act
        $event = new UserJoined($user, $roomId);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertInstanceOf(PresenceChannel::class, $channels[1]);
        $this->assertEquals("chat.room.{$roomId}", $channels[0]->name);
        $this->assertEquals("presence-chat.room.{$roomId}.presence", $channels[1]->name);
    }

    public function test_user_joined_event_has_correct_broadcast_name()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;

        // Act
        $event = new UserJoined($user, $roomId);

        // Assert
        $this->assertEquals('user.joined', $event->broadcastAs());
    }

    public function test_user_joined_event_broadcasts_correct_data()
    {
        // Arrange
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $roomId = 123;

        // Act
        $event = new UserJoined($user, $roomId);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('user', $broadcastData);
        $this->assertArrayHasKey('room_id', $broadcastData);
        $this->assertArrayHasKey('joined_at', $broadcastData);

        $userData = $broadcastData['user'];
        $this->assertEquals($user->id, $userData['id']);
        $this->assertEquals('John Doe', $userData['name']);
        $this->assertEquals('john@example.com', $userData['email']);

        $this->assertEquals($roomId, $broadcastData['room_id']);
        $this->assertNotNull($broadcastData['joined_at']);
    }

    public function test_user_joined_event_stores_correct_properties()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;

        // Act
        $event = new UserJoined($user, $roomId);

        // Assert
        $this->assertInstanceOf(User::class, $event->user);
        $this->assertEquals($user->id, $event->user->id);
        $this->assertEquals($roomId, $event->roomId);
    }
}
