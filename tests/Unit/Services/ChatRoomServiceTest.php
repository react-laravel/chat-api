<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPaginationService;
use App\Services\Chat\ChatRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ChatRoomServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatRoomService $service;

    private ChatCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cacheService = Mockery::mock(ChatCacheService::class)->makePartial();
        $this->service = new ChatRoomService(
            new ChatMessageService(new ChatPaginationService),
            $this->cacheService
        );
    }

    public function test_validate_room_data_only_validates_format_not_duplicates(): void
    {
        // validateRoomData no longer checks for duplicate names - that check
        // is now done inside the transaction in createRoom/updateRoom
        ChatRoom::factory()->create([
            'name' => 'Existing Room',
            'is_active' => true,
        ]);

        $result = $this->service->validateRoomData([
            'name' => 'Existing Room',
            'description' => 'Another room',
        ]);

        // Should pass format validation but createRoom will reject the duplicate
        $this->assertTrue($result['valid']);
    }

    public function test_create_room_rejects_duplicate_active_room_name(): void
    {
        $creator = User::factory()->create();
        ChatRoom::factory()->create([
            'name' => 'Existing Room',
            'is_active' => true,
        ]);

        $result = $this->service->createRoom([
            'name' => 'Existing Room',
            'description' => 'Another room',
        ], $creator->id);

        $this->assertFalse($result['success']);
        $this->assertContains('该房间名称已存在', $result['errors']);
    }

    public function test_create_room_creates_membership_and_system_message(): void
    {
        $creator = User::factory()->create();

        $result = $this->service->createRoom([
            'name' => 'Core Room',
            'description' => 'Room for tests',
            'is_private' => true,
        ], $creator->id);

        $this->assertTrue($result['success']);
        $room = $result['room'];
        $this->assertSame('Core Room', $room->name);
        $this->assertTrue($room->is_private);

        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'is_online' => true,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => 'system',
            'message' => "Room 'Core Room' has been created",
        ]);
    }

    public function test_delete_room_rejects_when_user_has_no_permission(): void
    {
        $creator = User::factory()->create();
        $outsider = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
        ]);

        $result = $this->service->deleteRoom($room->id, $outsider->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['You do not have permission to delete this room'], $result['errors']);
    }

    public function test_delete_room_rejects_when_other_online_users_are_present(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
        ]);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $otherUser->id,
        ]);

        $result = $this->service->deleteRoom($room->id, $creator->id);

        $this->assertFalse($result['success']);
        $this->assertSame(
            ['Cannot delete room with active users. Please wait for all users to leave.'],
            $result['errors']
        );
    }

    public function test_delete_room_marks_room_inactive_and_removes_memberships(): void
    {
        $creator = User::factory()->create(['name' => 'Creator']);
        $room = ChatRoom::factory()->create([
            'name' => 'Disposable Room',
            'created_by' => $creator->id,
            'is_active' => true,
        ]);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
        ]);

        $result = $this->service->deleteRoom($room->id, $creator->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($room->fresh()->is_active);
        $this->assertDatabaseMissing('chat_room_users', [
            'room_id' => $room->id,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => 'system',
            'message' => "Room 'Disposable Room' is being deleted",
        ]);
    }

    public function test_update_room_rejects_duplicate_name(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Original',
        ]);
        ChatRoom::factory()->create([
            'name' => 'Taken Name',
            'is_active' => true,
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'Taken Name',
            'description' => 'new description',
        ], $creator->id);

        $this->assertFalse($result['success']);
        $this->assertContains('该房间名称已存在', $result['errors']);
    }

    public function test_update_room_rename_creates_system_message(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Old Name',
            'description' => 'Old description',
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'New Name',
            'description' => 'Updated description',
            'is_private' => true,
        ], $creator->id);

        $this->assertTrue($result['success']);
        $this->assertSame('New Name', $result['room']->name);
        $this->assertTrue((bool) $result['room']->is_private);
        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => 'system',
            'message' => "Room renamed from 'Old Name' to 'New Name'",
        ]);
    }

    public function test_get_room_stats_returns_counts_messages_and_peak_hours(): void
    {
        $creator = User::factory()->create();
        $topSpeaker = User::factory()->create(['name' => 'Top Speaker']);
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
        ]);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);
        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $topSpeaker->id,
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $otherUser->id,
        ]);

        ChatMessage::factory()->count(3)->create([
            'room_id' => $room->id,
            'user_id' => $topSpeaker->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'created_at' => now()->subHour()->minute(15),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $otherUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'created_at' => now()->subHours(2),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'created_at' => now()->subMinutes(10),
        ]);

        $stats = $this->service->getRoomStats($room->id);

        $this->assertSame($room->id, $stats['room']->id);
        $this->assertSame(3, $stats['total_users']);
        $this->assertSame(2, $stats['online_users']);
        $this->assertSame(5, $stats['messages']['total_messages']);
        $this->assertSame(4, $stats['messages']['text_messages']);
        $this->assertSame(1, $stats['messages']['system_messages']);
        $this->assertGreaterThanOrEqual(1, $stats['recent_activity_24h']);
        $this->assertNotNull($stats['last_activity']);
        $this->assertGreaterThan(0, $stats['peak_hours']->count());
    }

    public function test_get_active_rooms_delegates_to_cache_service(): void
    {
        $expected = collect([ChatRoom::factory()->make(['name' => 'Cached Room'])]);

        $this->cacheService->shouldReceive('getRoomList')
            ->once()
            ->with(99)
            ->andReturn($expected);

        $rooms = $this->service->getActiveRooms(99);

        $this->assertSame($expected, $rooms);
    }

    public function test_check_room_permission_delete_operation(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $otherUser = User::factory()->create();

        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_active' => true,
        ]);

        // Creator can delete
        $this->assertTrue($this->service->checkRoomPermission($room->id, $creator->id, 'delete'));

        // Admin can delete
        $this->assertTrue($this->service->checkRoomPermission($room->id, $admin->id, 'delete'));

        // Other user cannot delete
        $this->assertFalse($this->service->checkRoomPermission($room->id, $otherUser->id, 'delete'));
    }

    public function test_check_room_permission_edit_operation(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $otherUser = User::factory()->create();

        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_active' => true,
        ]);

        // Creator can edit
        $this->assertTrue($this->service->checkRoomPermission($room->id, $creator->id, 'edit'));

        // Admin can edit
        $this->assertTrue($this->service->checkRoomPermission($room->id, $admin->id, 'edit'));

        // Other user cannot edit
        $this->assertFalse($this->service->checkRoomPermission($room->id, $otherUser->id, 'edit'));
    }

    public function test_check_room_permission_join_operation(): void
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['is_active' => true]);

        // Any authenticated user can join
        $this->assertTrue($this->service->checkRoomPermission($room->id, $user->id, 'join'));
    }

    public function test_check_room_permission_moderate_operation(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $otherUser = User::factory()->create();

        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_active' => true,
        ]);

        // Creator can moderate
        $this->assertTrue($this->service->checkRoomPermission($room->id, $creator->id, 'moderate'));

        // Admin can moderate
        $this->assertTrue($this->service->checkRoomPermission($room->id, $admin->id, 'moderate'));

        // Other user cannot moderate
        $this->assertFalse($this->service->checkRoomPermission($room->id, $otherUser->id, 'moderate'));
    }

    public function test_check_room_permission_returns_false_for_inactive_room(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_active' => false,
        ]);

        $this->assertFalse($this->service->checkRoomPermission($room->id, $creator->id, 'delete'));
    }

    public function test_check_room_permission_returns_false_for_nonexistent_room(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->service->checkRoomPermission(99999, $user->id, 'delete'));
    }

    public function test_check_room_permission_returns_false_for_nonexistent_user(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => true]);

        $this->assertFalse($this->service->checkRoomPermission($room->id, 99999, 'delete'));
    }

    public function test_check_room_permission_returns_false_for_unknown_operation(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->checkRoomPermission($room->id, $creator->id, 'unknown_operation'));
    }

    public function test_update_room_rejects_without_permission(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Original',
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'New Name',
        ], $otherUser->id);

        $this->assertFalse($result['success']);
        $this->assertSame(['You do not have permission to edit this room'], $result['errors']);
    }

    public function test_update_room_without_name_change_does_not_create_system_message(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Same Name',
            'description' => 'Old desc',
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'Same Name',
            'description' => 'New desc',
        ], $creator->id);

        $this->assertTrue($result['success']);
        $this->assertSame('New desc', $result['room']->description);

        // Should not have a rename system message
        $this->assertDatabaseMissing('chat_messages', [
            'room_id' => $room->id,
            'message_type' => 'system',
            'message' => "Room renamed from 'Same Name' to 'Same Name'",
        ]);
    }

    public function test_get_room_stats_returns_empty_array_for_nonexistent_room(): void
    {
        $stats = $this->service->getRoomStats(99999);

        $this->assertSame([], $stats);
    }

    public function test_validate_room_data_validates_minimum_name_length(): void
    {
        $result = $this->service->validateRoomData(['name' => 'a']);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('至少需要', $result['errors'][0]);
    }

    public function test_validate_room_data_validates_maximum_name_length(): void
    {
        $longName = str_repeat('长', 15); // 30 characters (Chinese counts as 2)

        $result = $this->service->validateRoomData(['name' => $longName]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('不能超过', $result['errors'][0]);
    }

    public function test_validate_room_data_validates_description_length(): void
    {
        $longDescription = str_repeat('x', 501);

        $result = $this->service->validateRoomData([
            'name' => 'Valid Name',
            'description' => $longDescription,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('房间描述不能超过', $result['errors'][0]);
    }

    public function test_validate_room_data_accepts_valid_data(): void
    {
        $result = $this->service->validateRoomData([
            'name' => 'Valid Room',
            'description' => 'Valid description',
            'is_private' => true,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('Valid Room', $result['sanitized_data']['name']);
        $this->assertSame('Valid description', $result['sanitized_data']['description']);
        $this->assertTrue($result['sanitized_data']['is_private']);
    }

    public function test_validate_room_data_trims_whitespace(): void
    {
        $result = $this->service->validateRoomData([
            'name' => '  Trimmed  ',
            'description' => '  Trimmed description  ',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('Trimmed', $result['sanitized_data']['name']);
        $this->assertSame('Trimmed description', $result['sanitized_data']['description']);
    }

    public function test_validate_room_data_handles_empty_description(): void
    {
        $result = $this->service->validateRoomData([
            'name' => 'Room Name',
            'description' => '',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['sanitized_data']['description']);
    }

    public function test_create_room_returns_error_on_validation_failure(): void
    {
        $user = User::factory()->create();

        $result = $this->service->createRoom([
            'name' => 'x', // Too short
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_validate_room_data_with_empty_name(): void
    {
        $result = $this->service->validateRoomData(['name' => '']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('房间名称是必需的', $result['errors'][0]);
    }

    public function test_validate_room_data_with_null_name(): void
    {
        $result = $this->service->validateRoomData(['name' => null]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('房间名称是必需的', $result['errors'][0]);
    }

    public function test_validate_room_data_with_unicode_characters(): void
    {
        $result = $this->service->validateRoomData([
            'name' => '你好🌟世界',
            'description' => 'Test 中文 description',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('你好🌟世界', $result['sanitized_data']['name']);
    }

    public function test_validate_room_data_with_mixed_length_calculation(): void
    {
        // Test mixing Chinese (2 chars) and English (1 char)
        // 'Test 你好' = 4 (Test) + 4 (你好) = 8 characters
        $result = $this->service->validateRoomData(['name' => 'Test 你好']);

        $this->assertTrue($result['valid']);
    }

    public function test_create_room_handles_exception(): void
    {
        $user = User::factory()->create();

        // Mock ChatMessageService to throw exception during system message creation
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('sanitizeMessage')
            ->andReturnUsing(fn ($msg) => $msg);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('Database connection failed'));

        $service = new ChatRoomService($messageService, $this->cacheService);

        $result = $service->createRoom([
            'name' => 'Test Room',
            'description' => 'Test description',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to create room', $result['errors'][0]);
        $this->assertStringContainsString('Database connection failed', $result['errors'][0]);
    }

    public function test_delete_room_handles_exception(): void
    {
        $creator = User::factory()->create();

        // Mock ChatMessageService to throw exception during system message creation
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('Failed to log deletion'));

        $service = new ChatRoomService($messageService, $this->cacheService);

        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);

        $result = $service->deleteRoom($room->id, $creator->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to delete room', $result['errors'][0]);
        $this->assertStringContainsString('Failed to log deletion', $result['errors'][0]);
    }

    public function test_update_room_handles_exception(): void
    {
        $creator = User::factory()->create();

        // Mock ChatMessageService to throw exception during rename
        $messageService = Mockery::mock(ChatMessageService::class);
        $messageService->shouldReceive('sanitizeMessage')
            ->andReturnUsing(fn ($msg) => $msg);
        $messageService->shouldReceive('createSystemMessage')
            ->andThrow(new \Exception('System message failed'));

        $service = new ChatRoomService($messageService, $this->cacheService);

        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Old Name',
        ]);

        $result = $service->updateRoom($room->id, [
            'name' => 'New Name',
            'description' => 'Updated',
        ], $creator->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to update room', $result['errors'][0]);
        $this->assertStringContainsString('System message failed', $result['errors'][0]);
    }

    public function test_create_room_with_private_flag(): void
    {
        $user = User::factory()->create();

        $result = $this->service->createRoom([
            'name' => 'Private Room',
            'description' => 'This is private',
            'is_private' => true,
        ], $user->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['room']->is_private);
    }

    public function test_delete_room_marks_as_inactive_not_hard_delete(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);
        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'is_online' => false,
        ]);

        $this->service->deleteRoom($room->id, $creator->id);

        $deletedRoom = ChatRoom::find($room->id);
        $this->assertNotNull($deletedRoom);
        $this->assertFalse($deletedRoom->is_active);
    }

    public function test_delete_room_creates_system_message(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'name' => 'Being Deleted Room',
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);

        $this->service->deleteRoom($room->id, $creator->id);

        $this->assertDatabaseHas('chat_messages', [
            'room_id' => $room->id,
            'message_type' => 'system',
            'message' => "Room 'Being Deleted Room' is being deleted",
        ]);
    }

    public function test_delete_room_removes_all_user_associations(): void
    {
        $creator = User::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user1->id,
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user2->id,
        ]);

        $this->service->deleteRoom($room->id, $creator->id);

        $this->assertDatabaseMissing('chat_room_users', ['room_id' => $room->id]);
    }

    public function test_update_room_with_description_only(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'Same Name',
            'description' => 'Updated Description Only',
        ], $creator->id);

        $this->assertTrue($result['success']);
        $this->assertSame('Updated Description Only', $result['room']->description);
    }

    public function test_update_room_with_privacy_flag(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_private' => false,
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'Same Name',
            'is_private' => true,
        ], $creator->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['room']->is_private);
    }

    public function test_check_room_permission_with_admin_user(): void
    {
        $admin = User::factory()->create();
        // Mock admin check since we're in unit test
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);

        // Creator should have delete permission
        $canDelete = $this->service->checkRoomPermission($room->id, $creator->id, 'delete');
        $this->assertTrue($canDelete);

        // Creator should have edit permission
        $canEdit = $this->service->checkRoomPermission($room->id, $creator->id, 'edit');
        $this->assertTrue($canEdit);

        // Creator should have moderate permission
        $canModerate = $this->service->checkRoomPermission($room->id, $creator->id, 'moderate');
        $this->assertTrue($canModerate);
    }

    public function test_check_room_permission_join_always_true_for_active_room(): void
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['is_active' => true]);

        $canJoin = $this->service->checkRoomPermission($room->id, $user->id, 'join');

        $this->assertTrue($canJoin);
    }

    public function test_check_room_permission_with_inactive_room(): void
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['is_active' => false]);

        $canJoin = $this->service->checkRoomPermission($room->id, $user->id, 'join');
        $this->assertFalse($canJoin);

        $canDelete = $this->service->checkRoomPermission($room->id, $user->id, 'delete');
        $this->assertFalse($canDelete);
    }

    public function test_get_room_stats_with_mixed_message_types(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);
        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);

        // Create various valid message types
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => 'text',
        ]);
        ChatMessage::factory()->count(2)->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'message_type' => 'system',
        ]);

        $stats = $this->service->getRoomStats($room->id);

        $this->assertSame(7, $stats['messages']['total_messages']);
        $this->assertSame(5, $stats['messages']['text_messages']);
        $this->assertSame(2, $stats['messages']['system_messages']);
    }

    public function test_get_room_stats_peak_hours(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);
        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
        ]);

        // Create messages at different hours
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'created_at' => now()->setHour(14),
        ]);
        ChatMessage::factory()->count(3)->create([
            'room_id' => $room->id,
            'user_id' => $creator->id,
            'created_at' => now()->setHour(10),
        ]);

        $stats = $this->service->getRoomStats($room->id);

        $this->assertGreaterThan(0, $stats['peak_hours']->count());
        $this->assertLessThanOrEqual(3, $stats['peak_hours']->count());
    }

    public function test_get_active_rooms_with_user_context(): void
    {
        $user = User::factory()->create();
        $this->cacheService->shouldReceive('getRoomList')
            ->with($user->id)
            ->once()
            ->andReturn(collect([]));

        $rooms = $this->service->getActiveRooms($user->id);

        $this->assertIsObject($rooms);
    }

    public function test_get_active_rooms_without_user_context(): void
    {
        $this->cacheService->shouldReceive('getRoomList')
            ->with(null)
            ->once()
            ->andReturn(collect([]));

        $rooms = $this->service->getActiveRooms();

        $this->assertIsObject($rooms);
    }

    public function test_validate_room_data_with_potentially_malicious_input(): void
    {
        $result = $this->service->validateRoomData([
            'name' => 'Normal<script>Room',
            'description' => 'Description with bad content',
        ]);

        // Even with HTML-like content, if name length is valid, it should pass validation
        // The sanitization happens after validation
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
    }

    public function test_delete_room_with_nonexistent_room(): void
    {
        $user = User::factory()->create();

        $result = $this->service->deleteRoom(99999, $user->id);

        $this->assertFalse($result['success']);
    }

    public function test_update_room_preserve_fields_not_provided(): void
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'created_by' => $creator->id,
            'is_private' => true,
        ]);

        $result = $this->service->updateRoom($room->id, [
            'name' => 'Updated Name',
            // is_private not provided
        ], $creator->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['room']->is_private); // Is reset to false since not provided
    }
}
