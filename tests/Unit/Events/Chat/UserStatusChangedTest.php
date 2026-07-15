<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\UserStatusChanged;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_status_changed_event_broadcasts_on_correct_channels()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;
        $isOnline = true;

        // Act
        $event = new UserStatusChanged($user, $roomId, $isOnline);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertInstanceOf(PresenceChannel::class, $channels[1]);
        $this->assertEquals("chat.room.{$roomId}", $channels[0]->name);
        $this->assertEquals("presence-chat.room.{$roomId}.presence", $channels[1]->name);
    }

    public function test_user_status_changed_event_has_correct_broadcast_name()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;
        $isOnline = true;

        // Act
        $event = new UserStatusChanged($user, $roomId, $isOnline);

        // Assert
        $this->assertEquals('user.status.changed', $event->broadcastAs());
    }

    public function test_user_status_changed_event_broadcasts_correct_data_when_online()
    {
        // Arrange
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $roomId = 123;
        $isOnline = true;

        // Act
        $event = new UserStatusChanged($user, $roomId, $isOnline);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('user', $broadcastData);
        $this->assertArrayHasKey('room_id', $broadcastData);
        $this->assertArrayHasKey('is_online', $broadcastData);
        $this->assertArrayHasKey('status_changed_at', $broadcastData);

        $userData = $broadcastData['user'];
        $this->assertEquals($user->id, $userData['id']);
        $this->assertEquals('John Doe', $userData['name']);
        $this->assertEquals('john@example.com', $userData['email']);

        $this->assertEquals($roomId, $broadcastData['room_id']);
        $this->assertTrue($broadcastData['is_online']);
        $this->assertNotNull($broadcastData['status_changed_at']);
    }

    public function test_user_status_changed_event_broadcasts_correct_data_when_offline()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;
        $isOnline = false;

        // Act
        $event = new UserStatusChanged($user, $roomId, $isOnline);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertFalse($broadcastData['is_online']);
    }

    public function test_user_status_changed_event_stores_correct_properties()
    {
        // Arrange
        $user = User::factory()->create();
        $roomId = 123;
        $isOnline = true;

        // Act
        $event = new UserStatusChanged($user, $roomId, $isOnline);

        // Assert
        $this->assertInstanceOf(User::class, $event->user);
        $this->assertEquals($user->id, $event->user->id);
        $this->assertEquals($roomId, $event->roomId);
        $this->assertTrue($event->isOnline);
    }
}
