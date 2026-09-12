<?php

use App\Models\Chat\ChatRoomUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 聊天房间频道，用于实时消息通信（私有认证频道）
Broadcast::channel('chat.room.{roomId}', function ($user, $roomId) {
    $inRoom = ChatRoomUser::where('room_id', $roomId)
        ->where('user_id', $user->id)
        ->whereHas('room', fn ($rooms) => $rooms->where('is_active', true))
        ->first();
    $inRoom = $inRoom && (! $inRoom->isBanned() || $user->canModerate($inRoom->room));

    return $inRoom ? [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ] : false;
});

// 聊天房间在线用户频道（私有认证频道，用于监听用户状态变化）
Broadcast::channel('chat.room.{roomId}.users', function ($user, $roomId) {
    $inRoom = ChatRoomUser::where('room_id', $roomId)
        ->where('user_id', $user->id)
        ->whereHas('room', fn ($rooms) => $rooms->where('is_active', true))
        ->first();
    $inRoom = $inRoom && (! $inRoom->isBanned() || $user->canModerate($inRoom->room));

    return $inRoom ? [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'avatar' => $user->avatar ?? null,
    ] : false;
});

// 聊天房间「输入中」私有频道，用于 client event (whisper)
// 前端 echo.private('chat.room.{roomId}.typing') 订阅时自动加上 private- 前缀，
// Laravel 自动映射到 Broadcast::channel('chat.room.{roomId}.typing', ...)
Broadcast::channel('chat.room.{roomId}.typing', function ($user, $roomId) {
    $inRoom = ChatRoomUser::where('room_id', $roomId)
        ->where('user_id', $user->id)
        ->whereHas('room', fn ($rooms) => $rooms->where('is_active', true))
        ->first();
    $inRoom = $inRoom && (! $inRoom->isBanned() || $user->canModerate($inRoom->room));

    return $inRoom ? ['id' => $user->id, 'name' => $user->name] : false;
});

// 聊天房间的 presence 频道，用于实时跟踪在线用户状态
// 前端 echo.join('chat.room.{roomId}.presence') 订阅时自动加上 presence- 前缀
Broadcast::channel('chat.room.{roomId}.presence', function ($user, $roomId) {
    $member = ChatRoomUser::where('room_id', $roomId)->where('user_id', $user->id)
        ->whereHas('room', fn ($rooms) => $rooms->where('is_active', true))->first();
    if (! $member || ($member->isBanned() && ! $user->canModerate($member->room))) {
        return false;
    }

    return [
        'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
        'role' => $user->isAdmin() ? 'admin' : ($member->room->created_by === $user->id ? 'moderator' : 'user'),
        'joined_at' => $member->joined_at?->toISOString(), 'is_online' => true,
    ];
});

// 用户私有频道，用于发送通知
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// 用户通知私有频道(如：user.1.notifications)
Broadcast::channel('user.{userId}.notifications', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('chat-room-{roomId}', function ($user, $roomId) {
    $member = ChatRoomUser::where('room_id', $roomId)->where('user_id', $user->id)
        ->whereHas('room', fn ($rooms) => $rooms->where('is_active', true))->first();

    return $member && (! $member->isBanned() || $user->canModerate($member->room));
});

Broadcast::channel('chat-rooms-list', fn ($user) => true);
