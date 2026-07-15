<?php

namespace App\Providers;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Policies\Chat\ChatMessagePolicy;
use App\Policies\Chat\ChatModerationPolicy;
use App\Policies\Chat\ChatRoomPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class PolicyServiceProvider extends ServiceProvider
{
    protected $policies = [
        ChatRoom::class => ChatRoomPolicy::class,
        ChatMessage::class => ChatMessagePolicy::class,
        ChatRoomUser::class => ChatModerationPolicy::class,
    ];
}
