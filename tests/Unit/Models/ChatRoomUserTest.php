<?php

namespace Tests\Unit\Models;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatRoomUserTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $moderator;

    private ChatRoom $room;

    private ChatRoomUser $roomUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->moderator = User::factory()->create();
        $this->room = ChatRoom::factory()->create();
        $this->roomUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_chat_room_user_has_fillable_attributes()
    {
        $fillable = [
            'room_id', 'user_id', 'joined_at', 'last_seen_at', 'is_online',
            'is_muted', 'muted_until', 'is_banned', 'banned_until', 'muted_by', 'banned_by',
        ];

        $this->assertEquals($fillable, $this->roomUser->getFillable());
    }

    public function test_chat_room_user_casts_attributes_correctly()
    {
        $casts = [
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_online' => 'boolean',
            'is_muted' => 'boolean',
            'muted_until' => 'datetime',
            'is_banned' => 'boolean',
            'banned_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];

        foreach ($casts as $attribute => $cast) {
            $this->assertEquals($cast, $this->roomUser->getCasts()[$attribute]);
        }
    }

    public function test_chat_room_user_belongs_to_room()
    {
        $this->assertInstanceOf(ChatRoom::class, $this->roomUser->room);
        $this->assertEquals($this->room->id, $this->roomUser->room->id);
    }

    public function test_chat_room_user_belongs_to_user()
    {
        $this->assertInstanceOf(User::class, $this->roomUser->user);
        $this->assertEquals($this->user->id, $this->roomUser->user->id);
    }

    public function test_chat_room_user_belongs_to_muted_by_user()
    {
        $this->roomUser->update(['muted_by' => $this->moderator->id]);

        $this->assertInstanceOf(User::class, $this->roomUser->mutedByUser);
        $this->assertEquals($this->moderator->id, $this->roomUser->mutedByUser->id);
    }

    public function test_chat_room_user_belongs_to_banned_by_user()
    {
        $this->roomUser->update(['banned_by' => $this->moderator->id]);

        $this->assertInstanceOf(User::class, $this->roomUser->bannedByUser);
        $this->assertEquals($this->moderator->id, $this->roomUser->bannedByUser->id);
    }

    public function test_online_scope_returns_only_online_users()
    {
        $onlineUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'is_online' => true,
        ]);

        $offlineUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'is_online' => false,
        ]);

        $onlineUsers = ChatRoomUser::online()->get();

        $this->assertTrue($onlineUsers->contains($onlineUser));
        $this->assertFalse($onlineUsers->contains($offlineUser));
    }

    public function test_offline_scope_returns_only_offline_users()
    {
        $onlineUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'is_online' => true,
        ]);

        $offlineUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'is_online' => false,
        ]);

        $offlineUsers = ChatRoomUser::offline()->get();

        $this->assertFalse($offlineUsers->contains($onlineUser));
        $this->assertTrue($offlineUsers->contains($offlineUser));
    }

    public function test_in_room_scope_returns_users_in_specific_room()
    {
        $room2 = ChatRoom::factory()->create();

        $userInRoom1 = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $userInRoom2 = ChatRoomUser::factory()->create([
            'room_id' => $room2->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $room1Users = ChatRoomUser::inRoom($this->room->id)->get();

        $this->assertTrue($room1Users->contains($userInRoom1));
        $this->assertFalse($room1Users->contains($userInRoom2));
    }

    public function test_inactive_since_scope_returns_inactive_users()
    {
        $activeUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'last_seen_at' => Carbon::now()->subMinutes(2),
        ]);

        $inactiveUser = ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => User::factory()->create()->id,
            'last_seen_at' => Carbon::now()->subMinutes(10),
        ]);

        $inactiveUsers = ChatRoomUser::inactiveSince(5)->get();

        $this->assertFalse($inactiveUsers->contains($activeUser));
        $this->assertTrue($inactiveUsers->contains($inactiveUser));
    }

    public function test_mark_as_online_updates_status()
    {
        $this->roomUser->update(['is_online' => false]);

        $this->roomUser->markAsOnline();

        $this->assertTrue($this->roomUser->fresh()->is_online);
        $this->assertNotNull($this->roomUser->fresh()->last_seen_at);
    }

    public function test_mark_as_offline_updates_status()
    {
        $this->roomUser->update(['is_online' => true]);

        $this->roomUser->markAsOffline();

        $this->assertFalse($this->roomUser->fresh()->is_online);
        $this->assertNotNull($this->roomUser->fresh()->last_seen_at);
    }

    public function test_update_last_seen_updates_timestamp()
    {
        $oldTimestamp = $this->roomUser->last_seen_at;

        // Wait a moment to ensure timestamp difference
        sleep(1);

        $this->roomUser->updateLastSeen();

        $this->assertNotEquals($oldTimestamp, $this->roomUser->fresh()->last_seen_at);
    }

    public function test_is_inactive_returns_true_for_inactive_users()
    {
        $this->roomUser->update([
            'last_seen_at' => Carbon::now()->subMinutes(10),
        ]);

        $this->assertTrue($this->roomUser->isInactive(5));
    }

    public function test_is_inactive_returns_false_for_active_users()
    {
        $this->roomUser->update([
            'last_seen_at' => Carbon::now()->subMinutes(2),
        ]);

        $this->assertFalse($this->roomUser->isInactive(5));
    }

    public function test_is_inactive_returns_true_for_null_last_seen()
    {
        $this->roomUser->update(['last_seen_at' => null]);

        $this->assertTrue($this->roomUser->isInactive());
    }

    public function test_is_muted_returns_false_when_not_muted()
    {
        $this->roomUser->update(['is_muted' => false]);

        $this->assertFalse($this->roomUser->isMuted());
    }

    public function test_is_muted_returns_true_when_permanently_muted()
    {
        $this->roomUser->update([
            'is_muted' => true,
            'muted_until' => null,
        ]);

        $this->assertTrue($this->roomUser->isMuted());
    }

    public function test_is_muted_returns_true_when_temporarily_muted()
    {
        $this->roomUser->update([
            'is_muted' => true,
            'muted_until' => Carbon::now()->addHours(1),
        ]);

        $this->assertTrue($this->roomUser->isMuted());
    }

    public function test_is_muted_auto_unmutes_expired_mute()
    {
        $this->roomUser->update([
            'is_muted' => true,
            'muted_until' => Carbon::now()->subHours(1),
        ]);

        $this->assertFalse($this->roomUser->isMuted());
        $this->assertFalse($this->roomUser->fresh()->is_muted);
    }

    public function test_is_banned_returns_false_when_not_banned()
    {
        $this->roomUser->update(['is_banned' => false]);

        $this->assertFalse($this->roomUser->isBanned());
    }

    public function test_is_banned_returns_true_when_permanently_banned()
    {
        $this->roomUser->update([
            'is_banned' => true,
            'banned_until' => null,
        ]);

        $this->assertTrue($this->roomUser->isBanned());
    }

    public function test_is_banned_returns_true_when_temporarily_banned()
    {
        $this->roomUser->update([
            'is_banned' => true,
            'banned_until' => Carbon::now()->addHours(1),
        ]);

        $this->assertTrue($this->roomUser->isBanned());
    }

    public function test_is_banned_auto_unbans_expired_ban()
    {
        $this->roomUser->update([
            'is_banned' => true,
            'banned_until' => Carbon::now()->subHours(1),
        ]);

        $this->assertFalse($this->roomUser->isBanned());
        $this->assertFalse($this->roomUser->fresh()->is_banned);
    }

    public function test_mute_sets_mute_status()
    {
        $this->roomUser->mute($this->moderator->id, 60);

        $this->assertTrue($this->roomUser->fresh()->is_muted);
        $this->assertEquals($this->moderator->id, $this->roomUser->fresh()->muted_by);
        $this->assertNotNull($this->roomUser->fresh()->muted_until);
    }

    public function test_mute_without_duration_sets_permanent_mute()
    {
        $this->roomUser->mute($this->moderator->id);

        $this->assertTrue($this->roomUser->fresh()->is_muted);
        $this->assertNull($this->roomUser->fresh()->muted_until);
    }

    public function test_unmute_removes_mute_status()
    {
        $this->roomUser->update([
            'is_muted' => true,
            'muted_by' => $this->moderator->id,
            'muted_until' => Carbon::now()->addHours(1),
        ]);

        $this->roomUser->unmute();

        $this->assertFalse($this->roomUser->fresh()->is_muted);
        $this->assertNull($this->roomUser->fresh()->muted_by);
        $this->assertNull($this->roomUser->fresh()->muted_until);
    }

    public function test_ban_sets_ban_status()
    {
        $this->roomUser->ban($this->moderator->id, 60);

        $this->assertTrue($this->roomUser->fresh()->is_banned);
        $this->assertEquals($this->moderator->id, $this->roomUser->fresh()->banned_by);
        $this->assertNotNull($this->roomUser->fresh()->banned_until);
        $this->assertFalse($this->roomUser->fresh()->is_online);
    }

    public function test_ban_without_duration_sets_permanent_ban()
    {
        $this->roomUser->ban($this->moderator->id);

        $this->assertTrue($this->roomUser->fresh()->is_banned);
        $this->assertNull($this->roomUser->fresh()->banned_until);
    }

    public function test_unban_removes_ban_status()
    {
        $this->roomUser->update([
            'is_banned' => true,
            'banned_by' => $this->moderator->id,
            'banned_until' => Carbon::now()->addHours(1),
        ]);

        $this->roomUser->unban();

        $this->assertFalse($this->roomUser->fresh()->is_banned);
        $this->assertNull($this->roomUser->fresh()->banned_by);
        $this->assertNull($this->roomUser->fresh()->banned_until);
    }

    public function test_can_send_messages_returns_true_when_not_muted_or_banned()
    {
        $this->roomUser->update([
            'is_muted' => false,
            'is_banned' => false,
        ]);

        $this->assertTrue($this->roomUser->canSendMessages());
    }

    public function test_can_send_messages_returns_false_when_muted()
    {
        $this->roomUser->update([
            'is_muted' => true,
            'is_banned' => false,
        ]);

        $this->assertFalse($this->roomUser->canSendMessages());
    }

    public function test_can_send_messages_returns_false_when_banned()
    {
        $this->roomUser->update([
            'is_muted' => false,
            'is_banned' => true,
        ]);

        $this->assertFalse($this->roomUser->canSendMessages());
    }
}
