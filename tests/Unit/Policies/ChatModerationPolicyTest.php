<?php

namespace Tests\Unit\Policies;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Policies\Chat\ChatModerationPolicy;
use Tests\TestCase;

class ChatModerationPolicyTest extends TestCase
{
    private ChatModerationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ChatModerationPolicy;
    }

    private function createUser(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function createRoom(int $id, int $createdBy): ChatRoom
    {
        $room = new ChatRoom;
        $room->id = $id;
        $room->created_by = $createdBy;

        return $room;
    }

    private function createRoomUser(int $userId, int $roomId = 1): ChatRoomUser
    {
        $roomUser = new ChatRoomUser;
        $roomUser->user_id = $userId;
        $roomUser->room_id = $roomId;

        return $roomUser;
    }

    public function test_moderate_returns_true_for_room_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);

        $this->assertTrue($this->policy->moderate($user, $room));
    }

    public function test_moderate_returns_false_for_non_creator_non_admin(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 999);

        $this->assertFalse($this->policy->moderate($user, $room));
    }

    public function test_mute_returns_true_for_room_creator_mutable_user(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(999);

        $this->assertTrue($this->policy->mute($user, $room, $roomUser));
    }

    public function test_mute_returns_false_for_room_creator_muting_self(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(1);

        $this->assertFalse($this->policy->mute($user, $room, $roomUser));
    }

    public function test_mute_returns_false_for_non_moderator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 999);
        $roomUser = $this->createRoomUser(888);

        $this->assertFalse($this->policy->mute($user, $room, $roomUser));
    }

    public function test_ban_returns_true_for_room_creator_banning_other(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(999);

        $this->assertTrue($this->policy->ban($user, $room, $roomUser));
    }

    public function test_ban_returns_false_for_room_creator_banning_self(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(1);

        $this->assertFalse($this->policy->ban($user, $room, $roomUser));
    }

    public function test_ban_returns_false_for_non_moderator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 999);
        $roomUser = $this->createRoomUser(888);

        $this->assertFalse($this->policy->ban($user, $room, $roomUser));
    }

    public function test_kick_returns_true_for_room_creator_kicking_other(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(999);

        $this->assertTrue($this->policy->kick($user, $room, $roomUser));
    }

    public function test_kick_returns_false_for_room_creator_kicking_self(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        $roomUser = $this->createRoomUser(1);

        $this->assertFalse($this->policy->kick($user, $room, $roomUser));
    }

    public function test_kick_returns_false_for_non_moderator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 999);
        $roomUser = $this->createRoomUser(888);

        $this->assertFalse($this->policy->kick($user, $room, $roomUser));
    }

    public function test_mute_returns_false_for_user_not_in_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        // User 999 is in room 2, not room 1
        $roomUser = $this->createRoomUser(999, 2);

        $this->assertFalse($this->policy->mute($user, $room, $roomUser));
    }

    public function test_ban_returns_false_for_user_not_in_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        // User 999 is in room 2, not room 1
        $roomUser = $this->createRoomUser(999, 2);

        $this->assertFalse($this->policy->ban($user, $room, $roomUser));
    }

    public function test_kick_returns_false_for_user_not_in_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1, 1);
        // User 999 is in room 2, not room 1
        $roomUser = $this->createRoomUser(999, 2);

        $this->assertFalse($this->policy->kick($user, $room, $roomUser));
    }
}
