<?php

namespace App\Policies\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatRoomPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any rooms.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the room.
     */
    public function view(User $user, ChatRoom $room): bool
    {
        return $room->is_active;
    }

    /**
     * Determine whether the user can create rooms.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the room.
     */
    public function update(User $user, ChatRoom $room): bool
    {
        return $room->created_by === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the room.
     */
    public function delete(User $user, ChatRoom $room): bool
    {
        return $room->created_by === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can join the room.
     */
    public function join(User $user, ChatRoom $room): bool
    {
        return $room->is_active;
    }

    /**
     * Determine whether the user can moderate the room.
     */
    public function moderate(User $user, ChatRoom $room): bool
    {
        return $room->created_by === $user->id || $user->hasRole('admin');
    }
}
