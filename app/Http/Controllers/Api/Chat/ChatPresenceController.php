<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ChatPresenceController extends Controller
{
    use ChatControllerHelpers;

    protected ChatService $chatService;

    protected ChatCacheService $cacheService;

    public function __construct(ChatService $chatService, ChatCacheService $cacheService)
    {
        $this->chatService = $chatService;
        $this->cacheService = $cacheService;
    }

    /**
     * Get room online users
     */
    public function users(int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $room = $this->findActiveRoom($resolvedRoomId);

            $guard = $this->ensureUserInRoom($resolvedRoomId, $userId, 'You must join the room to view online users');
            if ($guard) {
                return $guard;
            }

            $onlineUsers = $this->chatService->getOnlineUsers($resolvedRoomId);

            return $this->success([
                'online_users' => $onlineUsers,
                'count' => $onlineUsers->count(),
            ], 'Online users retrieved successfully');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to retrieve online users',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to retrieve online users'
            );
        }
    }

    /**
     * Update user status (heartbeat)
     */
    public function heartbeat(int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $room = $this->findActiveRoom($resolvedRoomId);

            $result = $this->chatService->processHeartbeat($resolvedRoomId, $userId);

            if (empty($result['success'])) {
                return $this->error('Failed to update status', $result['errors'] ?? [], 404);
            }

            return $this->success([
                'last_seen_at' => $result['last_seen_at'],
            ], 'Status updated successfully');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to update user status',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to update status'
            );
        }
    }

    /**
     * Clean up offline users
     */
    public function cleanup(): JsonResponse
    {
        try {
            $result = $this->chatService->cleanupInactiveUsers();

            if (empty($result['success'])) {
                return $this->error('Failed to cleanup disconnected users', $result['errors'] ?? [], 500);
            }

            Log::info('Disconnected users cleanup', [
                'cleaned_users_count' => $result['cleaned_count'],
                'initiated_by' => $this->getCurrentUserId(),
            ]);

            return $this->success([
                'cleaned_users_count' => $result['cleaned_count'],
            ], $result['message'] ?? 'Cleanup done');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to cleanup disconnected users',
                $e,
                ['initiated_by' => $this->getCurrentUserId()],
                'Failed to cleanup disconnected users'
            );
        }
    }

    /**
     * Get user presence status in room
     */
    public function status(int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $room = $this->findActiveRoom($resolvedRoomId);

            $roomUser = $this->fetchRoomUser($resolvedRoomId, $userId);

            if (! $roomUser) {
                return $this->success([
                    'is_in_room' => false,
                    'is_online' => false,
                ], 'You are not in this room');
            }

            return $this->success([
                'is_in_room' => true,
                'is_online' => $roomUser->is_online,
                'joined_at' => $roomUser->joined_at,
                'last_seen_at' => $roomUser->last_seen_at,
                'is_inactive' => $roomUser->isInactive(),
            ], 'User presence status retrieved successfully');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to get user presence status',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to get user presence status'
            );
        }
    }

    /**
     * @param  mixed  $roomId
     * @return array{0: int, 1: int}
     */
    private function resolveUserAndRoomId($roomId): array
    {
        $userId = $this->getCurrentUserId();

        return [$userId, $this->normalizeRoomId($roomId)];
    }

    /**
     * @return array{room_id: int|null, user_id: int|null}
     */
    private function buildRoomErrorContext(?int $roomId, ?int $userId = null): array
    {
        return [
            'room_id' => $roomId,
            'user_id' => $userId,
        ];
    }
}
