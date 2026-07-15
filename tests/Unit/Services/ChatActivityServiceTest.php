<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ChatActivityService;
use App\Services\Chat\ChatCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_activity_returns_active_users_message_counts_and_join_leave_activity(): void
    {
        $service = new ChatActivityService(Mockery::mock(ChatCacheService::class));
        $room = ChatRoom::factory()->create();

        $onlineUser = User::factory()->create();
        $offlineActiveUser = User::factory()->create();
        $inactiveUser = User::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'joined_at' => now()->subDay(),
            'last_seen_at' => now()->subMinutes(10),
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $offlineActiveUser->id,
            'joined_at' => now()->subHours(6),
            'last_seen_at' => now()->subHours(2),
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $inactiveUser->id,
            'joined_at' => now()->subDays(2),
            'last_seen_at' => now()->subHours(30),
        ]);

        ChatMessage::factory()->count(2)->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'created_at' => now()->subMinutes(30),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $offlineActiveUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'created_at' => now()->subMinutes(20),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $inactiveUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'created_at' => now()->subHours(30),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'message' => "{$onlineUser->name} joined the room",
            'created_at' => now()->subMinutes(5),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $offlineActiveUser->id,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'message' => "{$offlineActiveUser->name} left the room",
            'created_at' => now()->subMinutes(4),
        ]);
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'message' => 'Room settings updated',
            'created_at' => now()->subMinutes(3),
        ]);

        $result = $service->getUserActivity($room->id, 24);

        $this->assertSame(24, $result['period_hours']);
        $this->assertSame(2, $result['total_active_users']);
        $this->assertSame(1, $result['currently_online']);

        $activeUsers = collect($result['active_users']);
        $this->assertCount(2, $activeUsers);
        $this->assertEqualsCanonicalizing(
            [$onlineUser->id, $offlineActiveUser->id],
            $activeUsers->pluck('user.id')->all()
        );

        $messageActivity = $result['message_activity']->keyBy('user_id');
        $this->assertSame(4, (int) $messageActivity[$onlineUser->id]->message_count);
        $this->assertSame(2, (int) $messageActivity[$offlineActiveUser->id]->message_count);
        $this->assertFalse($messageActivity->has($inactiveUser->id));

        $joinLeaveMessages = $result['join_leave_activity']->pluck('message')->all();
        $this->assertCount(2, $joinLeaveMessages);
        $this->assertContains("{$onlineUser->name} joined the room", $joinLeaveMessages);
        $this->assertContains("{$offlineActiveUser->name} left the room", $joinLeaveMessages);
        $this->assertNotContains('Room settings updated', $joinLeaveMessages);
    }

    public function test_get_presence_stats_returns_online_counts_and_sorted_room_activity(): void
    {
        $service = new ChatActivityService(Mockery::mock(ChatCacheService::class));

        $busyRoom = ChatRoom::factory()->create([
            'name' => 'Busy Room',
            'is_active' => true,
        ]);
        $quietRoom = ChatRoom::factory()->create([
            'name' => 'Quiet Room',
            'is_active' => true,
        ]);

        ChatRoomUser::factory()->online()->count(2)->create([
            'room_id' => $busyRoom->id,
        ]);
        ChatRoomUser::factory()->online()->create([
            'room_id' => $quietRoom->id,
        ]);
        ChatRoomUser::factory()->offline()->create([
            'room_id' => $quietRoom->id,
        ]);

        $result = $service->getPresenceStats();

        $this->assertSame(3, $result['total_online_users']);
        $this->assertSame(2, $result['active_rooms']);
        $this->assertNotNull($result['last_updated']);

        $roomActivity = $result['room_activity'];
        $this->assertCount(2, $roomActivity);
        $this->assertSame($busyRoom->id, $roomActivity[0]->id);
        $this->assertSame('Busy Room', $roomActivity[0]->name);
        $this->assertSame(2, $roomActivity[0]->online_count);
        $this->assertSame($quietRoom->id, $roomActivity[1]->id);
        $this->assertSame(1, $roomActivity[1]->online_count);
    }

    public function test_track_room_activity_delegates_to_cache_service(): void
    {
        $cacheService = Mockery::mock(ChatCacheService::class);
        $cacheService->shouldReceive('trackRoomActivity')
            ->once()
            ->with(12, 'message_sent', 34);

        $service = new ChatActivityService($cacheService);

        $service->trackRoomActivity(12, 'message_sent', 34);
    }
}
