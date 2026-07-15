<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use Illuminate\Support\Facades\DB;

class ChatActivityService
{
    protected ChatCacheService $cacheService;

    public function __construct(ChatCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get user activity tracking in a room
     */
    public function getUserActivity(int $roomId, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        // Get active users in the time period
        $activeUsers = ChatRoomUser::inRoom($roomId)
            ->where('last_seen_at', '>=', $since)
            ->with('user:id,name,email')
            ->get();

        // Get message activity grouped by user
        $messageActivity = ChatMessage::forRoom($roomId)
            ->where('created_at', '>=', $since)
            ->select('user_id', DB::raw('COUNT(*) as message_count'))
            ->groupBy('user_id')
            ->with('user:id,name,email')
            ->get();

        // Get join/leave activity
        $joinLeaveActivity = ChatMessage::forRoom($roomId)
            ->where('message_type', ChatMessage::TYPE_SYSTEM)
            ->where('created_at', '>=', $since)
            ->where(function ($query) {
                $query->where('message', 'LIKE', '%joined the room%')
                    ->orWhere('message', 'LIKE', '%left the room%');
            })
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return [
            'period_hours' => $hours,
            'active_users' => $activeUsers->map(function ($roomUser) {
                return [
                    'user' => $roomUser->user,
                    'last_seen_at' => $roomUser->last_seen_at,
                    'is_online' => $roomUser->is_online,
                    'joined_at' => $roomUser->joined_at,
                ];
            }),
            'message_activity' => $messageActivity,
            'join_leave_activity' => $joinLeaveActivity,
            'total_active_users' => $activeUsers->count(),
            'currently_online' => $activeUsers->where('is_online', true)->count(),
        ];
    }

    /**
     * Get presence stats for all rooms
     */
    public function getPresenceStats(): array
    {
        $totalOnlineUsers = ChatRoomUser::online()->count();
        $totalRoomsWithUsers = ChatRoomUser::online()
            ->distinct('room_id')
            ->count();

        $roomActivity = ChatRoom::where('is_active', true)
            ->withCount([
                'users as online_count' => function ($query) {
                    $query->where('is_online', true);
                },
            ])
            ->orderBy('online_count', 'desc')
            ->get(['id', 'name', 'online_count']);

        return [
            'total_online_users' => $totalOnlineUsers,
            'active_rooms' => $totalRoomsWithUsers,
            'room_activity' => $roomActivity,
            'last_updated' => now(),
        ];
    }

    /**
     * Track room activity
     */
    public function trackRoomActivity(int $roomId, string $action, int $userId): void
    {
        $this->cacheService->trackRoomActivity($roomId, $action, $userId);
    }
}
