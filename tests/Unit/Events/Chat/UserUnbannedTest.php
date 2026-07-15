<?php

namespace Tests\Unit\Events\Chat;

use App\Events\Chat\UserUnbanned;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserUnbannedTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_unbanned_event_broadcasts_on_correct_channel()
    {
        // Arrange
        $user = User::factory()->create();
        $moderator = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Act
        $event = new UserUnbanned($room->id, $user->id, $moderator->id);
        $channels = $event->broadcastOn();

        // Assert
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertEquals("chat.room.{$room->id}", $channels[0]->name);
    }

    public function test_user_unbanned_event_has_correct_broadcast_name()
    {
        // Arrange
        $user = User::factory()->create();
        $moderator = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Act
        $event = new UserUnbanned($room->id, $user->id, $moderator->id);

        // Assert
        $this->assertEquals('user.unbanned', $event->broadcastAs());
    }

    public function test_user_unbanned_event_broadcasts_correct_data()
    {
        // Arrange
        $user = User::factory()->create([
            'name' => 'Unbanned User',
            'email' => 'unbanned@example.com',
        ]);
        $moderator = User::factory()->create([
            'name' => 'Moderator',
            'email' => 'mod@example.com',
        ]);
        $room = ChatRoom::factory()->create(['name' => 'Test Room']);

        // Act
        $event = new UserUnbanned($room->id, $user->id, $moderator->id, 'Appeal accepted');
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertArrayHasKey('room_id', $broadcastData);
        $this->assertArrayHasKey('user_id', $broadcastData);
        $this->assertArrayHasKey('moderator_id', $broadcastData);
        $this->assertArrayHasKey('reason', $broadcastData);

        $this->assertEquals($room->id, $broadcastData['room_id']);
        $this->assertEquals($user->id, $broadcastData['user_id']);
        $this->assertEquals($moderator->id, $broadcastData['moderator_id']);
        $this->assertEquals('Appeal accepted', $broadcastData['reason']);
    }

    public function test_user_unbanned_event_handles_no_reason()
    {
        // Arrange
        $user = User::factory()->create();
        $moderator = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Act
        $event = new UserUnbanned($room->id, $user->id, $moderator->id);
        $broadcastData = $event->broadcastWith();

        // Assert
        $this->assertNull($broadcastData['reason']);
    }
}
