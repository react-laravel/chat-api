<?php

namespace App\Policies\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatMessagePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any messages.
     */
    public function viewAny(User $user, ChatRoom $room): bool
    {
        return $room->is_active;
    }

    /**
     * Determine whether the user can view the message.
     */
    public function view(User $user, ChatMessage $message): bool
    {
        return $message->room->is_active;
    }

    /**
     * Determine whether the user can create messages.
     */
    public function create(User $user, ChatRoom $room): bool
    {
        return $room->is_active;
    }

    /**
     * Determine whether the user can update the message.
     */
    public function update(User $user, ChatMessage $message): bool
    {
        return $message->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the message.
     */
    public function delete(User $user, ChatMessage $message): bool
    {
        return $message->user_id === $user->id
            || $message->room->created_by === $user->id
            || $user->hasRole('admin');
    }
}
