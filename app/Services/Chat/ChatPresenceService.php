<?php

namespace App\Services\Chat;

use App\Events\Chat\UserJoined;
use App\Events\Chat\UserJoinedRoom;
use App\Events\Chat\UserLeft;
use App\Events\Chat\UserLeftRoom;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatPresenceService
{
    protected ChatMessageService $messageService;

    protected ChatCacheService $cacheService;

    public function __construct(
        ChatMessageService $messageService,
        ChatCacheService $cacheService
    ) {
        $this->messageService = $messageService;
        $this->cacheService = $cacheService;
    }

    /**
     * Presence timeout settings
     */
    const PRESENCE_TIMEOUT_MINUTES = 5;

    const HEARTBEAT_INTERVAL_SECONDS = 30;

    /**
     * Update user's online status in a room
     */
    public function updateUserStatus(int $roomId, int $userId, bool $isOnline = true): array
    {
        try {
            $roomUser = ChatRoomUser::inRoom($roomId)->forUser($userId)->first();

            if (! $roomUser) {
                // User not in room, create entry if coming online
                if ($isOnline) {
                    $roomUser = ChatRoomUser::create([
                        'room_id' => $roomId,
                        'user_id' => $userId,
                        'joined_at' => now(),
                        'last_seen_at' => now(),
                        'last_read_message_id' => ChatMessage::forRoom($roomId)->max('id'),
                        'last_read_at' => now(),
                        'is_online' => true,
                    ]);
                } else {
                    return [
                        'success' => false,
                        'errors' => ['User not found in room'],
                    ];
                }
            } else {
                // Update existing entry
                $roomUser->update([
                    'is_online' => $isOnline,
                    'last_seen_at' => now(),
                ]);
            }

            return [
                'success' => true,
                'room_user' => $roomUser->load('user:id,name,email'),
                'status_changed' => true,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to update user status: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Handle user joining a room
     */
    public function joinRoom(int $roomId, int $userId): array
    {
        $room = ChatRoom::find($roomId);

        if (! $room || ! $room->is_active) {
            return [
                'success' => false,
                'errors' => ['Room not found or inactive'],
            ];
        }

        // Check if user is already a member (needed for private room check and re-join)
        $existingRoomUser = ChatRoomUser::inRoom($roomId)->forUser($userId)->first();

        if ($existingRoomUser?->isBanned() && ! User::find($userId)?->canModerate($room)) {
            return ['success' => false, 'errors' => ['You are banned from this room.'], 'status' => 403];
        }

        if ($room->is_private && ! $existingRoomUser && ! User::find($userId)?->isAdmin()) {
            return [
                'success' => false,
                'errors' => ['Private rooms are only joinable by existing members.'],
            ];
        }

        try {
            if ($existingRoomUser) {
                // User is already a member, just update online status
                $result = $this->updateUserStatus($roomId, $userId, true);

                return [
                    'success' => true,
                    'room_user' => $result['room_user'],
                    'room' => $room,
                    'message' => 'User is already a member of this room',
                ];
            }

            DB::beginTransaction();

            // Update or create user online status
            $result = $this->updateUserStatus($roomId, $userId, true);

            if (! $result['success']) {
                DB::rollBack();

                return $result;
            }

            // Create system message for user join
            $user = User::find($userId);
            $this->messageService->createSystemMessage($roomId, "{$user->name} joined the room", $userId);

            // Get current online count
            $onlineCount = ChatRoomUser::inRoom($roomId)->online()->count();

            // Broadcast user join event
            broadcast(new UserJoined($user, $roomId));

            // Broadcast online count change event
            broadcast(new UserJoinedRoom($roomId, $userId, $user->name, $onlineCount));

            DB::commit();

            return [
                'success' => true,
                'room_user' => $result['room_user'],
                'room' => $room,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'errors' => ['Failed to join room: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Handle user leaving a room
     */
    public function leaveRoom(int $roomId, int $userId): array
    {
        try {
            // Check if user is a member
            $roomUser = ChatRoomUser::inRoom($roomId)->forUser($userId)->first();

            if (! $roomUser) {
                return [
                    'success' => false,
                    'message' => 'User is not a member of this room',
                ];
            }

            DB::beginTransaction();

            // Update user status to offline
            $result = $this->updateUserStatus($roomId, $userId, false);

            if (! $result['success']) {
                DB::rollBack();

                return $result;
            }

            // Create system message for user leave
            $user = User::find($userId);
            $this->messageService->createSystemMessage($roomId, "{$user->name} left the room", $userId);

            // Get current online count
            $onlineCount = ChatRoomUser::inRoom($roomId)->online()->count();

            // Broadcast user leave event
            broadcast(new UserLeft($user, $roomId));

            // Broadcast online count change event
            broadcast(new UserLeftRoom($roomId, $userId, $user->name, $onlineCount));

            DB::commit();

            return [
                'success' => true,
                'message' => 'Successfully left the room',
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'errors' => ['Failed to leave room: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Get online users in a room (cached)
     */
    public function getOnlineUsers(int $roomId): Collection
    {
        return $this->cacheService->getOnlineUsers($roomId);
    }

    /**
     * Process heartbeat to keep user online
     */
    public function processHeartbeat(int $roomId, int $userId): array
    {
        try {
            $roomUser = ChatRoomUser::inRoom($roomId)->forUser($userId)->first();

            if (! $roomUser) {
                return [
                    'success' => false,
                    'errors' => ['User not found in room'],
                ];
            }

            $roomUser->update([
                'last_seen_at' => now(),
                'is_online' => true,
            ]);

            return [
                'success' => true,
                'last_seen_at' => $roomUser->last_seen_at,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to process heartbeat: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Clean up inactive users (mark as offline)
     */
    public function cleanupInactiveUsers(): array
    {
        try {
            $timeoutThreshold = now()->subMinutes(self::PRESENCE_TIMEOUT_MINUTES);

            $inactiveUsers = ChatRoomUser::online()
                ->where('last_seen_at', '<', $timeoutThreshold)
                ->get();

            if ($inactiveUsers->isEmpty()) {
                return [
                    'success' => true,
                    'cleaned_count' => 0,
                    'message' => 'No inactive users to clean up',
                ];
            }

            // Bulk update all inactive users to offline (1 query instead of N)
            ChatRoomUser::whereIn('id', $inactiveUsers->pluck('id'))
                ->update(['is_online' => false]);

            // Pre-load user names in a single query to avoid N+1
            $userIds = $inactiveUsers->pluck('user_id')->unique();
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');

            foreach ($inactiveUsers as $roomUser) {
                $user = $users->get($roomUser->user_id);

                if ($user) {
                    $this->messageService->createSystemMessage(
                        $roomUser->room_id,
                        "{$user->name} went offline due to inactivity",
                        $roomUser->user_id
                    );
                }
            }

            return [
                'success' => true,
                'cleaned_count' => $inactiveUsers->count(),
                'message' => "Cleaned up {$inactiveUsers->count()} inactive users",
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to cleanup inactive users: '.$e->getMessage()],
            ];
        }
    }
}
