<?php

namespace App\Policies\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatModerationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can moderate the room.
     */
    public function moderate(User $user, ChatRoom $room): bool
    {
        return $room->created_by === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can mute another user in the room.
     */
    public function mute(User $user, ChatRoom $room, ChatRoomUser $roomUser): bool
    {
        if ($room->created_by === $user->id || $user->hasRole('admin')) {
            return $roomUser->user_id !== $user->id && $roomUser->room_id === $room->id;
        }

        return false;
    }

    /**
     * Determine whether the user can ban another user from the room.
     */
    public function ban(User $user, ChatRoom $room, ChatRoomUser $roomUser): bool
    {
        if ($room->created_by === $user->id || $user->hasRole('admin')) {
            return $roomUser->user_id !== $user->id && $roomUser->room_id === $room->id;
        }

        return false;
    }

    /**
     * Determine whether the user can kick another user from the room.
     */
    public function kick(User $user, ChatRoom $room, ChatRoomUser $roomUser): bool
    {
        if ($room->created_by === $user->id || $user->hasRole('admin')) {
            return $roomUser->user_id !== $user->id && $roomUser->room_id === $room->id;
        }

        return false;
    }
}
