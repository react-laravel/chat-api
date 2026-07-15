<?php

namespace Tests\Unit\Policies;

use App\Models\Chat\ChatRoom;
use App\Models\User;
use App\Policies\Chat\ChatRoomPolicy;
use Tests\TestCase;

class ChatRoomPolicyTest extends TestCase
{
    private ChatRoomPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ChatRoomPolicy;
    }

    private function createUser(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function createRoom(int $createdBy, bool $isActive = true): ChatRoom
    {
        $room = new ChatRoom;
        $room->created_by = $createdBy;
        $room->is_active = $isActive;

        return $room;
    }

    public function test_view_any_returns_true(): void
    {
        $user = $this->createUser(1);
        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_create_returns_true(): void
    {
        $user = $this->createUser(1);
        $this->assertTrue($this->policy->create($user));
    }

    public function test_view_returns_true_for_active_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999, true);

        $this->assertTrue($this->policy->view($user, $room));
    }

    public function test_view_returns_false_for_inactive_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999, false);

        $this->assertFalse($this->policy->view($user, $room));
    }

    public function test_join_returns_true_for_active_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999, true);

        $this->assertTrue($this->policy->join($user, $room));
    }

    public function test_join_returns_false_for_inactive_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999, false);

        $this->assertFalse($this->policy->join($user, $room));
    }

    public function test_update_returns_true_for_room_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1);

        $this->assertTrue($this->policy->update($user, $room));
    }

    public function test_update_returns_false_for_non_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999);

        $this->assertFalse($this->policy->update($user, $room));
    }

    public function test_delete_returns_true_for_room_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1);

        $this->assertTrue($this->policy->delete($user, $room));
    }

    public function test_delete_returns_false_for_non_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999);

        $this->assertFalse($this->policy->delete($user, $room));
    }

    public function test_moderate_returns_true_for_room_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(1);

        $this->assertTrue($this->policy->moderate($user, $room));
    }

    public function test_moderate_returns_false_for_non_creator_non_admin(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(999);

        $this->assertFalse($this->policy->moderate($user, $room));
    }
}
