<?php

namespace Tests\Feature;

use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChatRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable broadcasting to avoid Pusher connection issues in tests
        Event::fake();

        $this->user = User::factory()->create();
        $this->room = ChatRoom::factory()->create([
            'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_history_pages_are_stable_for_messages_created_at_the_same_time(): void
    {
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->user->id]);
        $messages = ChatMessage::factory()->count(35)->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $first = $this->getJson('/api/chat/rooms/'.$this->room->id.'/messages?page[number]=1');
        $second = $this->getJson('/api/chat/rooms/'.$this->room->id.'/messages?page[number]=2');
        $first->assertOk()->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
        $second->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(5, 'data');
        $this->assertSame(
            $messages->pluck('id')->reverse()->values()->all(),
            array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))
        );
    }

    public function test_get_rooms_returns_active_rooms()
    {
        $activeRoom = ChatRoom::factory()->create(['is_active' => true]);
        $inactiveRoom = ChatRoom::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/chat/rooms');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.rooms'); // Including the room from setUp
        $response->assertJsonFragment(['id' => $activeRoom->id]);
        $roomIds = array_column($response->json('data.rooms'), 'id');
        $this->assertNotContains($inactiveRoom->id, $roomIds);
    }

    public function test_create_room_with_valid_data()
    {
        $roomData = [
            'name' => 'Test Room',
            'description' => 'A test chat room',
        ];

        $response = $this->postJson('/api/chat/rooms', $roomData);

        $response->assertStatus(201);
        $response->assertJsonPath('data.room.name', 'Test Room');
        $response->assertJsonPath('data.room.description', 'A test chat room');
        $response->assertJsonPath('data.room.created_by', $this->user->id);

        $this->assertDatabaseHas('chat_rooms', $roomData);
    }

    public function test_create_room_with_invalid_data()
    {
        $response = $this->postJson('/api/chat/rooms', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_create_room_with_name_too_short()
    {
        $response = $this->postJson('/api/chat/rooms', [
            'name' => 'a', // 1 个字符，少于最小长度 2
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertStringContainsString('至少需要', $response->json('errors.name.0'));
    }

    public function test_create_room_with_name_too_long()
    {
        $response = $this->postJson('/api/chat/rooms', [
            'name' => '中文字符测试用例超长名称', // 11 个中文字符 = 22 个字符长度，超过最大长度 20
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertStringContainsString('不能超过', $response->json('errors.name.0'));
    }

    public function test_join_room_successfully()
    {
        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/join");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Successfully joined the room']);

        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);
    }

    public function test_join_nonexistent_room()
    {
        $response = $this->postJson('/api/chat/rooms/999/join');

        $response->assertStatus(422);
    }

    public function test_leave_room_successfully()
    {
        // First join the room
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/leave");

        $response->assertStatus(200);

        $roomUser = ChatRoomUser::where('room_id', $this->room->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertFalse($roomUser->is_online);
    }

    public function test_delete_room_by_creator()
    {
        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('chat_rooms', [
            'id' => $this->room->id,
            'is_active' => false,
        ]);
    }

    public function test_delete_room_by_non_creator()
    {
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);

        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('chat_rooms', ['id' => $this->room->id]);
    }

    public function test_get_messages_requires_room_membership()
    {
        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/messages");

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You must join the room to view messages']);
    }

    public function test_get_messages_returns_paginated_messages()
    {
        // Join the room first
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        // Create some messages
        ChatMessage::factory()->count(5)->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/messages");

        $response->assertStatus(200);
        // getMessages returns raw paginator via response()->json(), not Controller::success()
        $response->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'per_page',
            'total',
        ]);
    }

    public function test_send_message_requires_room_membership()
    {
        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
    }

    public function test_send_message_requires_online_status()
    {
        // Join the room but set offline
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => false,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You must be online in the room to send messages']);
    }

    public function test_send_message_successfully()
    {
        Event::fake();

        // Join the room and set online
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['message' => 'Message sent successfully']);

        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Hello, world!',
        ]);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_send_message_respects_rate_limiting()
    {
        // Join the room and set online
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $lastResponse = null;
        for ($i = 0; $i < 11; $i++) {
            $lastResponse = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
                'message' => "Rate limited message {$i}",
            ]);
            if ($lastResponse->status() === 429) {
                break;
            }
        }

        $lastResponse->assertStatus(429);
    }

    public function test_send_message_blocked_by_content_filter()
    {
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'I hate spam and violence',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Message blocked by content filter',
            ]);

        $this->assertDatabaseMissing('chat_messages', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'I hate spam and violence',
        ]);
    }

    public function test_send_message_blocked_when_muted()
    {
        // Create a different user who is not the room creator
        $otherUser = User::factory()->create();

        // Join the room with the other user and set online but muted
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
            'is_online' => true,
            'is_muted' => true,
        ]);

        // Authenticate as the muted user
        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You are muted in this room']);
    }

    public function test_send_message_blocked_when_muted_until_specific_time()
    {
        $otherUser = User::factory()->create();
        $muteUntil = now()->addMinutes(15)->second(0);

        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
            'is_online' => true,
            'is_muted' => true,
            'muted_until' => $muteUntil,
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            'You are muted in this room until '.$muteUntil->format('Y-m-d H:i:s'),
            $response->json('message')
        );
    }

    public function test_send_message_blocked_when_banned()
    {
        // Create a different user who is not the room creator
        $otherUser = User::factory()->create();

        // Join the room with the other user and set online but banned
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
            'is_online' => true,
            'is_banned' => true,
        ]);

        // Authenticate as the banned user
        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You are banned from this room']);
    }

    public function test_send_message_blocked_when_banned_until_specific_time()
    {
        $otherUser = User::factory()->create();
        $banUntil = now()->addMinutes(30)->second(0);

        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
            'is_online' => true,
            'is_banned' => true,
            'banned_until' => $banUntil,
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Hello, world!',
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            'You are banned from this room until '.$banUntil->format('Y-m-d H:i:s'),
            $response->json('message')
        );
    }

    public function test_room_creator_can_send_message_even_when_marked_muted()
    {
        Event::fake();

        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
            'is_muted' => true,
            'muted_until' => now()->addHour(),
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Moderator override message',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Message sent successfully',
            ]);

        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Moderator override message',
        ]);
    }

    public function test_delete_message_by_author()
    {
        Event::fake();

        $message = ChatMessage::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}/messages/{$message->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Message deleted successfully']);

        $this->assertSoftDeleted('chat_messages', ['id' => $message->id]);
        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_delete_message_by_room_creator()
    {
        Event::fake();

        $otherUser = User::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}/messages/{$message->id}");

        $response->assertStatus(200);
        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_delete_message_unauthorized()
    {
        $otherUser = User::factory()->create();
        $otherRoom = ChatRoom::factory()->create(['created_by' => $otherUser->id]);

        $message = ChatMessage::factory()->create([
            'room_id' => $otherRoom->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->deleteJson("/api/chat/rooms/{$otherRoom->id}/messages/{$message->id}");

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You are not authorized to delete this message']);
    }

    public function test_delete_message_by_admin()
    {
        Event::fake();

        // Create an admin user
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        // Create a message by a different user in a room the admin doesn't own
        $otherUser = User::factory()->create();
        $otherRoom = ChatRoom::factory()->create(['created_by' => $otherUser->id]);
        $message = ChatMessage::factory()->create([
            'room_id' => $otherRoom->id,
            'user_id' => $otherUser->id,
        ]);

        // Admin should be able to delete any message
        $response = $this->deleteJson("/api/chat/rooms/{$otherRoom->id}/messages/{$message->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Message deleted successfully']);

        $this->assertSoftDeleted('chat_messages', ['id' => $message->id]);
        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_get_online_users_requires_room_membership()
    {
        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/users");

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You must join the room to view online users']);
    }

    public function test_get_online_users_returns_online_users()
    {
        // Join the room
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        // Add another online user
        $otherUser = User::factory()->create();
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $otherUser->id,
            'is_online' => true,
        ]);

        // Add an offline user
        $offlineUser = User::factory()->create();
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $offlineUser->id,
            'is_online' => false,
        ]);

        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/users");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'online_users',
                'count',
            ],
        ]);
        $response->assertJsonPath('data.count', 2);
    }

    public function test_update_user_status_updates_last_seen()
    {
        // Join the room
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/status");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Status updated successfully']);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['last_seen_at'],
        ]);
    }

    public function test_cleanup_disconnected_users()
    {
        $response = $this->postJson('/api/chat/cleanup-disconnected');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['cleaned_users_count'],
        ]);
    }

    public function test_update_user_status_returns_not_found_when_service_fails()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('processHeartbeat')
                ->once()
                ->andReturn([
                    'success' => false,
                    'errors' => ['User not found in room'],
                ]);
        });

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/status");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to update status')
            ->assertJsonPath('errors.0', 'User not found in room');
    }

    public function test_cleanup_disconnected_users_returns_error_when_service_fails()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('cleanupInactiveUsers')
                ->once()
                ->andReturn([
                    'success' => false,
                    'errors' => ['Cleanup crashed'],
                ]);
        });

        $response = $this->postJson('/api/chat/cleanup-disconnected');

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to cleanup disconnected users')
            ->assertJsonPath('errors.0', 'Cleanup crashed');
    }

    public function test_get_user_presence_status_when_not_in_room()
    {
        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/my-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_in_room', false);
        $response->assertJsonPath('data.is_online', false);
    }

    public function test_get_user_presence_status_when_in_room()
    {
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/my-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_in_room', true);
        $response->assertJsonPath('data.is_online', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'joined_at',
                'last_seen_at',
                'is_inactive',
            ],
        ]);
    }

    public function test_unauthenticated_requests_are_rejected()
    {
        auth('sanctum')->forgetUser();
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/chat/rooms');

        $response->assertStatus(401)
            ->assertJsonPath('message', '请先通过 DogeOW 统一登录');
    }

    public function test_create_room_with_is_private_true()
    {
        $roomData = [
            'name' => 'Private Test Room',
            'description' => 'A private room',
            'is_private' => true,
        ];

        $response = $this->postJson('/api/chat/rooms', $roomData);

        $response->assertStatus(201);
        $response->assertJsonPath('data.room.name', 'Private Test Room');
        $response->assertJsonPath('data.room.is_private', true);
        $this->assertDatabaseHas('chat_rooms', [
            'name' => 'Private Test Room',
            'is_private' => true,
        ]);
    }

    public function test_get_rooms_excludes_private_room_when_user_not_member()
    {
        $otherUser = User::factory()->create();
        $privateRoom = ChatRoom::factory()->private()->create([
            'name' => 'Private Room',
            'created_by' => $otherUser->id,
        ]);
        ChatRoomUser::create([
            'room_id' => $privateRoom->id,
            'user_id' => $otherUser->id,
            'is_online' => false,
        ]);

        $response = $this->getJson('/api/chat/rooms');

        $response->assertStatus(200);
        $roomIds = array_column($response->json('data.rooms'), 'id');
        $this->assertNotContains($privateRoom->id, $roomIds);
    }

    public function test_get_rooms_includes_private_room_when_user_is_member()
    {
        $privateRoom = ChatRoom::factory()->private()->create([
            'name' => 'My Private Room',
            'created_by' => $this->user->id,
        ]);
        ChatRoomUser::create([
            'room_id' => $privateRoom->id,
            'user_id' => $this->user->id,
            'is_online' => false,
        ]);

        $response = $this->getJson('/api/chat/rooms');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $privateRoom->id, 'name' => 'My Private Room']);
    }

    public function test_join_private_room_as_non_member_fails()
    {
        $otherUser = User::factory()->create();
        $privateRoom = ChatRoom::factory()->private()->create([
            'name' => 'Private Room',
            'created_by' => $otherUser->id,
        ]);
        ChatRoomUser::create([
            'room_id' => $privateRoom->id,
            'user_id' => $otherUser->id,
            'is_online' => false,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$privateRoom->id}/join");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('chat_room_users', [
            'room_id' => $privateRoom->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_join_private_room_as_creator_succeeds()
    {
        $privateRoom = ChatRoom::factory()->private()->create([
            'name' => 'My Private Room',
            'created_by' => $this->user->id,
        ]);
        ChatRoomUser::create([
            'room_id' => $privateRoom->id,
            'user_id' => $this->user->id,
            'is_online' => false,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$privateRoom->id}/join");

        $response->assertStatus(200);
        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $privateRoom->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);
    }

    public function test_update_room_successfully()
    {
        $response = $this->putJson("/api/chat/rooms/{$this->room->id}", [
            'name' => 'Updated Room Name',
            'description' => 'Updated description',
            'is_private' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.room.name', 'Updated Room Name');
        $response->assertJsonPath('data.room.description', 'Updated description');
        $response->assertJsonPath('data.room.is_private', true);
        $this->assertDatabaseHas('chat_rooms', [
            'id' => $this->room->id,
            'name' => 'Updated Room Name',
            'is_private' => true,
        ]);
    }

    public function test_get_rooms_returns_server_error_when_service_throws()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('getActiveRooms')
                ->once()
                ->with($this->user->id)
                ->andThrow(new \RuntimeException('rooms failed'));
        });

        $response = $this->getJson('/api/chat/rooms');

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to retrieve rooms');
    }

    public function test_create_room_returns_error_payload_when_service_rejects_creation()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('createRoom')
                ->once()
                ->with([
                    'name' => 'Rejected Room',
                    'description' => 'Nope',
                    'is_private' => false,
                ], $this->user->id)
                ->andReturn([
                    'success' => false,
                    'errors' => ['Room name already exists'],
                ]);
        });

        $response = $this->postJson('/api/chat/rooms', [
            'name' => 'Rejected Room',
            'description' => 'Nope',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Failed to create room')
            ->assertJsonPath('errors.0', 'Room name already exists');
    }

    public function test_create_room_returns_server_error_when_service_throws()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('createRoom')
                ->once()
                ->andThrow(new \RuntimeException('creation failed'));
        });

        $response = $this->postJson('/api/chat/rooms', [
            'name' => 'Exception Room',
            'description' => 'Fails loudly',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to create room');
    }

    public function test_update_room_returns_error_payload_when_service_rejects_update()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('updateRoom')
                ->once()
                ->with($this->room->id, [
                    'name' => 'Rejected Update',
                    'description' => 'Still nope',
                    'is_private' => true,
                ], $this->user->id)
                ->andReturn([
                    'success' => false,
                    'errors' => ['Room update was rejected'],
                ]);
        });

        $response = $this->putJson("/api/chat/rooms/{$this->room->id}", [
            'name' => 'Rejected Update',
            'description' => 'Still nope',
            'is_private' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Failed to update room')
            ->assertJsonPath('errors.0', 'Room update was rejected');
    }

    public function test_update_room_returns_server_error_when_service_throws()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('updateRoom')
                ->once()
                ->andThrow(new \RuntimeException('update exploded'));
        });

        $response = $this->putJson("/api/chat/rooms/{$this->room->id}", [
            'name' => 'Exploding Update',
            'description' => 'Boom',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to update room');
    }

    public function test_join_room_returns_server_error_when_service_throws()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('joinRoom')
                ->once()
                ->with($this->room->id, $this->user->id)
                ->andThrow(new \RuntimeException('join failed'));
        });

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/join");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to join room');
    }

    public function test_leave_room_returns_error_payload_when_service_rejects_leave()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('leaveRoom')
                ->once()
                ->with($this->room->id, $this->user->id)
                ->andReturn([
                    'success' => false,
                    'errors' => ['User is not a member of this room'],
                ]);
        });

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/leave");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Failed to leave room')
            ->assertJsonPath('errors.0', 'User is not a member of this room');
    }

    public function test_delete_room_returns_422_for_non_permission_service_error()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('deleteRoom')
                ->once()
                ->with($this->room->id, $this->user->id)
                ->andReturn([
                    'success' => false,
                    'errors' => ['Cannot delete room with active users. Please wait for all users to leave.'],
                ]);
        });

        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Failed to delete room')
            ->assertJsonPath('errors.0', 'Cannot delete room with active users. Please wait for all users to leave.');
    }

    public function test_get_messages_returns_server_error_when_room_is_missing()
    {
        $response = $this->getJson('/api/chat/rooms/999999/messages');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to retrieve messages');
    }

    public function test_send_message_returns_generic_error_when_service_fails_without_specific_errors()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->with($this->room->id, $this->user->id, 'This will fail', ChatMessage::TYPE_TEXT)
                ->andReturn(['success' => false]);
        });

        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'This will fail',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Failed to send message')
            ->assertJsonPath('errors.errors', []);
    }

    public function test_delete_message_returns_server_error_when_message_is_missing()
    {
        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}/messages/999999");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to delete message');
    }

    public function test_get_online_users_returns_server_error_when_room_is_missing()
    {
        $response = $this->getJson('/api/chat/rooms/999999/users');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to retrieve online users');
    }

    public function test_update_user_status_returns_server_error_when_room_is_missing()
    {
        $response = $this->postJson('/api/chat/rooms/999999/status');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to update status');
    }

    public function test_cleanup_disconnected_users_returns_server_error_when_service_throws()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('cleanupInactiveUsers')
                ->once()
                ->andThrow(new \RuntimeException('cleanup exploded'));
        });

        $response = $this->postJson('/api/chat/cleanup-disconnected');

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to cleanup disconnected users');
    }

    public function test_get_user_presence_status_returns_server_error_when_room_is_missing()
    {
        $response = $this->getJson('/api/chat/rooms/999999/my-status');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Failed to get user presence status');
    }

    public function test_leave_room_handles_exception_from_service()
    {
        ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);

        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('leaveRoom')
                ->once()
                ->andThrow(new \RuntimeException('Database error'));
        });

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/leave");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to leave room');
    }

    public function test_delete_room_handles_exception_from_service()
    {
        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('deleteRoom')
                ->once()
                ->andThrow(new \RuntimeException('Database error'));
        });

        $response = $this->deleteJson("/api/chat/rooms/{$this->room->id}");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to delete room');
    }

    public function test_send_message_handles_exception_from_service()
    {
        ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        $this->mock(ChatService::class, function ($mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andThrow(new \RuntimeException('Database error'));
        });

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", [
            'message' => 'Test message',
            'message_type' => 'text',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to send message');
    }

    public function test_unauthenticated_user_cannot_access_chat_routes(): void
    {
        // Refresh the application to clear any authentication state from setUp()
        $this->refreshApplication();

        $response = $this->getJson('/api/chat/rooms');
        $response->assertStatus(401);

        $response = $this->postJson('/api/chat/rooms', ['name' => 'Test']);
        $response->assertStatus(401);

        $response = $this->getJson("/api/chat/rooms/{$this->room->id}/messages");
        $response->assertStatus(401);

        $response = $this->postJson("/api/chat/rooms/{$this->room->id}/messages", ['message' => 'Test']);
        $response->assertStatus(401);
    }
}
