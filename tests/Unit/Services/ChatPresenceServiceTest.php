<?php

namespace Tests\Unit\Services;

use App\Events\Chat\UserJoinedRoom;
use App\Events\Chat\UserLeftRoom;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPaginationService;
use App\Services\Chat\ChatPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ChatPresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatPresenceService $service;

    private ChatCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);
        Event::fake();

        $this->cacheService = Mockery::mock(ChatCacheService::class);
        $this->service = new ChatPresenceService(
            new ChatMessageService(new ChatPaginationService),
            $this->cacheService
        );
    }

    public function test_update_user_status_creates_membership_when_user_comes_online(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $result = $this->service->updateUserStatus($room->id, $user->id, true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_online' => true,
        ]);
        $this->assertSame($user->id, $result['room_user']->user->id);
    }

    public function test_update_user_status_returns_error_when_offline_user_is_not_in_room(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $result = $this->service->updateUserStatus($room->id, $user->id, false);

        $this->assertFalse($result['success']);
        $this->assertSame(['User not found in room'], $result['errors']);
    }

    public function test_join_room_reactivates_existing_member_without_creating_system_message(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->service->joinRoom($room->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('User is already a member of this room', $result['message']);
        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_online' => true,
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'room_id' => $room->id,
            'message_type' => 'system',
            'message' => "{$user->name} joined the room",
        ]);
    }

    public function test_join_room_rejects_private_room_for_non_member(): void
    {
        $room = ChatRoom::factory()->private()->create();
        $user = User::factory()->create();

        $result = $this->service->joinRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['Private rooms are only joinable by existing members.'], $result['errors']);
    }

    public function test_leave_room_marks_user_offline_and_creates_system_message(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create(['name' => 'Leave User']);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->service->leaveRoom($room->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('Successfully left the room', $result['message']);
        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_online' => false,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message_type' => 'system',
            'message' => "{$user->name} left the room",
        ]);
        Event::assertDispatched(UserLeftRoom::class);
        Event::assertNotDispatched(UserJoinedRoom::class);
    }

    public function test_process_heartbeat_updates_last_seen_and_online_status(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();
        $roomUser = ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'last_seen_at' => now()->subHour(),
        ]);

        $result = $this->service->processHeartbeat($room->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($roomUser->fresh()->is_online);
        $this->assertTrue($roomUser->fresh()->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_cleanup_inactive_users_marks_them_offline_and_logs_system_message(): void
    {
        $staleUser = User::factory()->create(['name' => 'Stale User']);
        $freshUser = User::factory()->create(['name' => 'Fresh User']);
        $room = ChatRoom::factory()->create();

        $staleRoomUser = ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $staleUser->id,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $freshRoomUser = ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $freshUser->id,
            'last_seen_at' => now()->subMinute(),
        ]);

        $result = $this->service->cleanupInactiveUsers();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['cleaned_count']);
        $this->assertFalse($staleRoomUser->fresh()->is_online);
        $this->assertTrue($freshRoomUser->fresh()->is_online);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $staleUser->id,
            'message_type' => 'system',
            'message' => "{$staleUser->name} went offline due to inactivity",
        ]);
    }

    public function test_update_user_status_updates_existing_member(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();
        $roomUser = ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'last_seen_at' => now()->subHour(),
        ]);

        $result = $this->service->updateUserStatus($room->id, $user->id, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($roomUser->fresh()->is_online);
        $this->assertTrue($roomUser->fresh()->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_join_room_returns_error_for_inactive_room(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => false]);
        $user = User::factory()->create();

        $result = $this->service->joinRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['Room not found or inactive'], $result['errors']);
    }

    public function test_join_room_returns_error_for_nonexistent_room(): void
    {
        $user = User::factory()->create();

        $result = $this->service->joinRoom(99999, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['Room not found or inactive'], $result['errors']);
    }

    public function test_join_room_creates_system_message_for_new_member(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['name' => 'New Member']);

        $result = $this->service->joinRoom($room->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('message', $result);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message_type' => 'system',
            'message' => "{$user->name} joined the room",
        ]);
    }

    public function test_join_room_allows_existing_member_in_private_room(): void
    {
        $room = ChatRoom::factory()->private()->create();
        $user = User::factory()->create();
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->service->joinRoom($room->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('User is already a member of this room', $result['message']);
    }

    public function test_leave_room_returns_error_when_user_not_in_room(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $result = $this->service->leaveRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame('User is not a member of this room', $result['message']);
    }

    public function test_process_heartbeat_returns_error_when_user_not_in_room(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $result = $this->service->processHeartbeat($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['User not found in room'], $result['errors']);
    }

    public function test_cleanup_inactive_users_returns_zero_when_all_active(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();
        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'last_seen_at' => now(),
        ]);

        $result = $this->service->cleanupInactiveUsers();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['cleaned_count']);
    }

    public function test_get_online_users_delegates_to_cache_service(): void
    {
        $expected = collect([
            User::factory()->make(['name' => 'User 1']),
            User::factory()->make(['name' => 'User 2']),
        ]);

        $this->cacheService->shouldReceive('getOnlineUsers')
            ->once()
            ->with(123)
            ->andReturn($expected);

        $result = $this->service->getOnlineUsers(123);

        $this->assertSame($expected, $result);
    }

    public function test_join_room_handles_exception_when_creating_system_message(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['name' => 'Test User']);

        // Mock ChatMessageService to throw exception
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('Failed to create message'));

        $service = new ChatPresenceService($messageService, $this->cacheService);

        $result = $service->joinRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to join room', $result['errors'][0]);
        $this->assertStringContainsString('Failed to create message', $result['errors'][0]);
    }

    public function test_leave_room_handles_exception_when_creating_system_message(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create(['name' => 'Test User']);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Mock ChatMessageService to throw exception
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('Failed to log leave'));

        $service = new ChatPresenceService($messageService, $this->cacheService);

        $result = $service->leaveRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to leave room', $result['errors'][0]);
        $this->assertStringContainsString('Failed to log leave', $result['errors'][0]);
    }

    public function test_cleanup_inactive_users_handles_exception(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $room = ChatRoom::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        // Mock ChatMessageService to throw exception
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('Failed to log cleanup'));

        $service = new ChatPresenceService($messageService, $this->cacheService);

        $result = $service->cleanupInactiveUsers();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to cleanup inactive users', $result['errors'][0]);
        $this->assertStringContainsString('Failed to log cleanup', $result['errors'][0]);
    }

    public function test_join_room_handles_update_user_status_failure(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['name' => 'Test User']);

        // Create a partial mock of ChatPresenceService
        $messageService = new ChatMessageService(new ChatPaginationService);
        $service = Mockery::mock(ChatPresenceService::class, [$messageService, $this->cacheService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock updateUserStatus to return failure
        $service->shouldReceive('updateUserStatus')
            ->andReturn([
                'success' => false,
                'errors' => ['Simulated update failure'],
            ]);

        $result = $service->joinRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['Simulated update failure'], $result['errors']);
    }

    public function test_leave_room_handles_update_user_status_failure(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create(['name' => 'Test User']);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Create a partial mock of ChatPresenceService
        $messageService = new ChatMessageService(new ChatPaginationService);
        $service = Mockery::mock(ChatPresenceService::class, [$messageService, $this->cacheService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock updateUserStatus to return failure
        $service->shouldReceive('updateUserStatus')
            ->andReturn([
                'success' => false,
                'errors' => ['Simulated update failure'],
            ]);

        $result = $service->leaveRoom($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['Simulated update failure'], $result['errors']);
    }

    public function test_update_user_status_handles_database_exception(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        Schema::dropIfExists('chat_room_users');
        $this->assertFalse(Schema::hasTable('chat_room_users'));

        $result = $this->service->updateUserStatus($room->id, $user->id, true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to update user status:', $result['errors'][0]);
    }

    public function test_process_heartbeat_handles_database_exception(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        Schema::dropIfExists('chat_room_users');
        $this->assertFalse(Schema::hasTable('chat_room_users'));

        $result = $this->service->processHeartbeat($room->id, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to process heartbeat:', $result['errors'][0]);
    }
}
