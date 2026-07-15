<?php

namespace Tests\Unit\Policies;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use App\Policies\Chat\ChatMessagePolicy;
use Tests\TestCase;

class ChatMessagePolicyTest extends TestCase
{
    private ChatMessagePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ChatMessagePolicy;
    }

    private function createUser(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function createRoom(bool $isActive = true): ChatRoom
    {
        return (new ChatRoom)->forceFill([
            'is_active' => $isActive,
        ]);
    }

    private function createMessage(int $userId, ChatRoom $room): ChatMessage
    {
        $message = (new ChatMessage)->forceFill([
            'user_id' => $userId,
        ]);
        $message->setRelation('room', $room);

        return $message;
    }

    public function test_view_any_returns_true_for_active_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);

        $this->assertTrue($this->policy->viewAny($user, $room));
    }

    public function test_view_any_returns_false_for_inactive_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(false);

        $this->assertFalse($this->policy->viewAny($user, $room));
    }

    public function test_view_returns_true_for_active_room_message(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $message = $this->createMessage(999, $room);

        $this->assertTrue($this->policy->view($user, $message));
    }

    public function test_view_returns_false_for_inactive_room_message(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(false);
        $message = $this->createMessage(999, $room);

        $this->assertFalse($this->policy->view($user, $message));
    }

    public function test_create_returns_true_for_active_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);

        $this->assertTrue($this->policy->create($user, $room));
    }

    public function test_create_returns_false_for_inactive_room(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(false);

        $this->assertFalse($this->policy->create($user, $room));
    }

    public function test_update_returns_true_for_message_owner(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $message = $this->createMessage(1, $room);

        $this->assertTrue($this->policy->update($user, $message));
    }

    public function test_update_returns_false_for_non_owner(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $message = $this->createMessage(999, $room);

        $this->assertFalse($this->policy->update($user, $message));
    }

    public function test_delete_returns_true_for_message_owner(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $message = $this->createMessage(1, $room);

        $this->assertTrue($this->policy->delete($user, $message));
    }

    public function test_delete_returns_true_for_room_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $room->created_by = 1;
        $message = $this->createMessage(999, $room);

        $this->assertTrue($this->policy->delete($user, $message));
    }

    public function test_delete_returns_false_for_non_owner_non_creator(): void
    {
        $user = $this->createUser(1);
        $room = $this->createRoom(true);
        $room->created_by = 999;
        $message = $this->createMessage(999, $room);

        $this->assertFalse($this->policy->delete($user, $message));
    }
}
