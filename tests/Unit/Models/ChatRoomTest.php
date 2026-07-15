<?php

namespace Tests\Unit\Models;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_room_can_be_created()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create([
            'name' => 'Test Room',
            'description' => 'A test room',
            'created_by' => $user->id,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(ChatRoom::class, $room);
        $this->assertEquals('Test Room', $room->name);
        $this->assertEquals('A test room', $room->description);
        $this->assertEquals($user->id, $room->created_by);
        $this->assertTrue($room->is_active);
    }

    public function test_creator_relationship()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $room->creator);
        $this->assertEquals($user->id, $room->creator->id);
    }

    public function test_messages_relationship()
    {
        $room = ChatRoom::factory()->create();
        $message = ChatMessage::factory()->create(['room_id' => $room->id]);

        $this->assertInstanceOf(ChatMessage::class, $room->messages->first());
        $this->assertEquals($message->id, $room->messages->first()->id);
    }

    public function test_users_relationship()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $room->users->first());
        $this->assertEquals($user->id, $room->users->first()->id);
    }

    public function test_online_users_relationship()
    {
        $room = ChatRoom::factory()->create();
        $onlineUser = User::factory()->create();
        $offlineUser = User::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'is_online' => true,
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $offlineUser->id,
            'is_online' => false,
        ]);

        $onlineUsers = $room->onlineUsers;

        $this->assertCount(1, $onlineUsers);
        $this->assertEquals($onlineUser->id, $onlineUsers->first()->id);
    }

    public function test_active_scope()
    {
        ChatRoom::factory()->create(['is_active' => true]);
        ChatRoom::factory()->create(['is_active' => false]);

        $activeRooms = ChatRoom::active()->get();

        $this->assertCount(1, $activeRooms);
        $this->assertTrue($activeRooms->first()->is_active);
    }

    public function test_online_count_attribute()
    {
        $room = ChatRoom::factory()->create();
        $onlineUser = User::factory()->create();
        $offlineUser = User::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
            'is_online' => true,
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $offlineUser->id,
            'is_online' => false,
        ]);

        $this->assertEquals(1, $room->online_count);
    }

    public function test_is_active_is_casted_to_boolean()
    {
        $room = ChatRoom::factory()->create(['is_active' => 1]);

        $this->assertIsBool($room->is_active);
        $this->assertTrue($room->is_active);
    }

    public function test_created_at_and_updated_at_are_casted_to_datetime()
    {
        $room = ChatRoom::factory()->create();

        $this->assertInstanceOf(Carbon::class, $room->created_at);
        $this->assertInstanceOf(Carbon::class, $room->updated_at);
    }

    public function test_room_can_have_multiple_messages()
    {
        $room = ChatRoom::factory()->create();
        $message1 = ChatMessage::factory()->create(['room_id' => $room->id]);
        $message2 = ChatMessage::factory()->create(['room_id' => $room->id]);

        $this->assertCount(2, $room->messages);
        $this->assertContains($message1->id, $room->messages->pluck('id'));
        $this->assertContains($message2->id, $room->messages->pluck('id'));
    }

    public function test_room_can_have_multiple_users()
    {
        $room = ChatRoom::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user1->id,
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user2->id,
        ]);

        $this->assertCount(2, $room->users);
        $this->assertContains($user1->id, $room->users->pluck('id'));
        $this->assertContains($user2->id, $room->users->pluck('id'));
    }

    public function test_room_users_relationship()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_online' => true,
        ]);

        $this->assertCount(1, $room->roomUsers);
        $this->assertInstanceOf(ChatRoomUser::class, $room->roomUsers->first());
    }

    public function test_online_room_users_relationship()
    {
        $room = ChatRoom::factory()->create();
        $onlineUser = User::factory()->create();
        $offlineUser = User::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $onlineUser->id,
        ]);

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $offlineUser->id,
        ]);

        $onlineRoomUsers = $room->onlineRoomUsers;

        $this->assertCount(1, $onlineRoomUsers);
        $this->assertTrue($onlineRoomUsers->first()->is_online);
    }

    public function test_total_users_count_attribute()
    {
        $room = ChatRoom::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user1->id,
        ]);

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user2->id,
        ]);

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user3->id,
        ]);

        $this->assertEquals(3, $room->total_users_count);
    }

    public function test_latest_message_relationship()
    {
        $room = ChatRoom::factory()->create();
        $oldMessage = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'created_at' => now()->subHours(2),
        ]);
        $latestMessageRecord = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'created_at' => now(),
        ]);

        $this->assertNotNull($room->latestMessage);
        $this->assertEquals($latestMessageRecord->id, $room->latestMessage->id);
    }

    public function test_has_recent_activity_returns_true_for_recent_messages()
    {
        $room = ChatRoom::factory()->create();
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'created_at' => now()->subHours(12),
        ]);

        $this->assertTrue($room->hasRecentActivity(24));
    }

    public function test_has_recent_activity_returns_false_for_old_messages()
    {
        $room = ChatRoom::factory()->create();
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'created_at' => now()->subHours(48),
        ]);

        $this->assertFalse($room->hasRecentActivity(24));
    }

    public function test_has_recent_activity_returns_false_for_no_messages()
    {
        $room = ChatRoom::factory()->create();

        $this->assertFalse($room->hasRecentActivity(24));
    }

    public function test_is_created_by_returns_true_for_creator()
    {
        $creator = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);

        $this->assertTrue($room->isCreatedBy($creator->id));
    }

    public function test_is_created_by_returns_false_for_non_creator()
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $creator->id]);

        $this->assertFalse($room->isCreatedBy($otherUser->id));
    }

    public function test_has_user_online_returns_true_when_user_is_online()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($room->hasUserOnline($user->id));
    }

    public function test_has_user_online_returns_false_when_user_is_offline()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $this->assertFalse($room->hasUserOnline($user->id));
    }

    public function test_has_user_online_returns_false_when_user_not_in_room()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $this->assertFalse($room->hasUserOnline($user->id));
    }

    public function test_stats_attribute()
    {
        $room = ChatRoom::factory()->create(['created_at' => now()->subDays(5)]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ChatRoomUser::factory()->online()->create([
            'room_id' => $room->id,
            'user_id' => $user1->id,
        ]);

        ChatRoomUser::factory()->offline()->create([
            'room_id' => $room->id,
            'user_id' => $user2->id,
        ]);

        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'created_at' => now()->subHours(12),
        ]);

        $stats = $room->stats;

        $this->assertEquals(2, $stats['total_users']);
        $this->assertEquals(1, $stats['online_users']);
        $this->assertEquals(5, $stats['total_messages']);
        $this->assertTrue($stats['recent_activity']);
        $this->assertGreaterThanOrEqual(4, $stats['created_days_ago']);
        $this->assertLessThanOrEqual(6, $stats['created_days_ago']);
    }

    public function test_with_recent_activity_scope()
    {
        $activeRoom = ChatRoom::factory()->create();
        $inactiveRoom = ChatRoom::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $activeRoom->id,
            'created_at' => now()->subHours(12),
        ]);

        ChatMessage::factory()->create([
            'room_id' => $inactiveRoom->id,
            'created_at' => now()->subDays(3),
        ]);

        $roomsWithActivity = ChatRoom::withRecentActivity(24)->get();

        $this->assertCount(1, $roomsWithActivity);
        $this->assertEquals($activeRoom->id, $roomsWithActivity->first()->id);
    }

    public function test_popular_scope()
    {
        $room1 = ChatRoom::factory()->create();
        $room2 = ChatRoom::factory()->create();
        $room3 = ChatRoom::factory()->create();

        // Room 1: 3 users
        ChatRoomUser::factory()->count(3)->create(['room_id' => $room1->id]);

        // Room 2: 1 user
        ChatRoomUser::factory()->create(['room_id' => $room2->id]);

        // Room 3: 5 users
        ChatRoomUser::factory()->count(5)->create(['room_id' => $room3->id]);

        $popularRooms = ChatRoom::popular(2)->get();

        $this->assertCount(2, $popularRooms);
        $this->assertEquals($room3->id, $popularRooms[0]->id);
        $this->assertEquals($room1->id, $popularRooms[1]->id);
    }
}
