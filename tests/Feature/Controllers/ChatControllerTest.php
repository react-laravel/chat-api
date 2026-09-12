<?php

namespace Tests\Feature\Controllers;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_can_get_rooms()
    {
        $user = User::factory()->create();
        ChatRoom::factory()->count(3)->create();

        $response = $this->actingAs($user)
            ->get('/api/chat/rooms');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(3, $data['data']['rooms']);
    }

    #[Test]
    public function it_can_create_room()
    {
        $user = User::factory()->create();
        $roomData = [
            'name' => 'Test Room',
            'description' => 'A test room',
        ];

        $response = $this->actingAs($user)
            ->post('/api/chat/rooms', $roomData);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('Test Room', $data['data']['room']['name']);
        $this->assertEquals('A test room', $data['data']['room']['description']);
    }

    #[Test]
    public function it_validates_room_creation_data()
    {
        $user = User::factory()->create();
        $invalidData = [
            'name' => 'a',
            'description' => 'A test room',
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/chat/rooms', $invalidData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function it_can_join_room()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $response = $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Successfully joined the room', $data['message']);
        $this->assertArrayHasKey('room', $data['data']);
        $this->assertArrayHasKey('room_user', $data['data']);
    }

    #[Test]
    public function it_can_leave_room()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // First join the room
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        // Then leave it
        $response = $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/leave");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Successfully left the room', $data['message']);
    }

    #[Test]
    public function it_can_delete_room()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)
            ->delete("/api/chat/rooms/{$room->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Room deleted successfully', $data['message']);
    }

    #[Test]
    public function it_denies_room_deletion_to_non_owner()
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $owner->id]);

        $response = $this->actingAs($otherUser)
            ->delete("/api/chat/rooms/{$room->id}");

        $response->assertStatus(403);
        $data = $response->json();
        $this->assertEquals('Failed to delete room', $data['message']);
    }

    #[Test]
    public function it_can_get_messages()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Join the room first
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        // Create some messages
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get("/api/chat/rooms/{$room->id}/messages");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertGreaterThan(0, count($data['data'] ?? []));
    }

    #[Test]
    public function it_can_send_message()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Join the room first
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $messageData = [
            'message' => 'Hello, this is a test message!',
            'message_type' => 'text',
        ];

        $response = $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/messages", $messageData);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('data', $data['data']);
        $messageData = $data['data']['data'] ?? $data['data'];
        $this->assertArrayHasKey('message', $messageData);
        $this->assertNotEmpty($messageData['message']);
    }

    #[Test]
    public function it_validates_message_data()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $response = $this->actingAs($user)
            ->postJson("/api/chat/rooms/{$room->id}/messages", [
                'message' => '',
                'message_type' => 'text',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    #[Test]
    public function it_can_delete_message()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->delete("/api/chat/rooms/{$room->id}/messages/{$message->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Message deleted successfully', $data['message']);
    }

    #[Test]
    public function it_denies_message_deletion_to_non_owner()
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($otherUser)
            ->delete("/api/chat/rooms/{$room->id}/messages/{$message->id}");

        $response->assertStatus(403);
        $data = $response->json();
        $this->assertEquals('You are not authorized to delete this message', $data['message']);
    }

    #[Test]
    public function it_allows_admin_to_delete_any_message()
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($admin)
            ->delete("/api/chat/rooms/{$room->id}/messages/{$message->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Message deleted successfully', $data['message']);
    }

    #[Test]
    public function it_can_get_online_users()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Join the room
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $response = $this->actingAs($user)
            ->get("/api/chat/rooms/{$room->id}/users");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('online_users', $data['data']);
        $this->assertIsArray($data['data']['online_users']);
    }

    #[Test]
    public function it_can_update_user_status()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Join the room first
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $statusData = [
            'is_online' => true,
        ];

        $response = $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/status", $statusData);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('Status updated successfully', $data['message']);
    }

    #[Test]
    public function it_can_cleanup_disconnected_users()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/api/chat/cleanup-disconnected');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('No inactive users to clean up', $data['message']);
    }

    #[Test]
    public function it_can_get_user_presence_status()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        // Join the room first
        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $response = $this->actingAs($user)
            ->get("/api/chat/rooms/{$room->id}/my-status");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('is_online', $data['data']);
        $this->assertArrayHasKey('last_seen_at', $data['data']);
    }

    #[Test]
    public function it_handles_rate_limiting_for_messages()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $lastResponse = null;
        for ($i = 0; $i < 11; $i++) {
            $lastResponse = $this->actingAs($user)
                ->postJson("/api/chat/rooms/{$room->id}/messages", [
                    'message' => "rate-limit-message-{$i}",
                    'message_type' => 'text',
                ]);
            if ($lastResponse->status() === 429) {
                break;
            }
        }

        $lastResponse->assertStatus(429);
    }

    #[Test]
    public function it_handles_room_not_found()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/chat/rooms/999999/messages');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Failed to retrieve messages',
            ]);
    }

    #[Test]
    public function it_handles_message_not_found()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $this->actingAs($user)
            ->post("/api/chat/rooms/{$room->id}/join");

        $response = $this->actingAs($user)
            ->deleteJson("/api/chat/rooms/{$room->id}/messages/999999");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Failed to delete message',
            ]);
    }

    #[Test]
    public function it_requires_authentication()
    {
        $this->getJson('/api/chat/rooms')
            ->assertStatus(401)
            ->assertJson([
                'message' => '请先通过 DogeOW 统一登录',
            ]);
    }

    #[Test]
    public function it_handles_invalid_room_id()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/chat/rooms/invalid/join');

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Room not found or inactive',
            ]);
    }
}
