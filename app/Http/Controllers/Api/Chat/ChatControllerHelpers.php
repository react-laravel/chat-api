<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Requests\Chat\GetModerationActionsRequest;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ChatControllerHelpers
{
    /**
     * Check if user is in room
     */
    protected function isUserInRoom(int $roomId, int $userId): bool
    {
        return ChatRoomUser::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Get room user info
     */
    protected function fetchRoomUser(int $roomId, int $userId): ?ChatRoomUser
    {
        return ChatRoomUser::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Invalidate related cache
     */
    protected function clearRoomCache(int $roomId): void
    {
        $this->cacheService->invalidateOnlineUsers($roomId);
        $this->cacheService->invalidateRoomStats($roomId);
        $this->cacheService->invalidateRoomList();
    }

    /**
     * Clear room cache and log activity
     */
    protected function clearCacheAndLogActivity(int $roomId, string $action, int $userId): void
    {
        $this->clearRoomCache($roomId);
        $this->logRoomActivity($roomId, $action, $userId);
    }

    /**
     * Log room activity
     */
    protected function logRoomActivity(int $roomId, string $action, int $userId): void
    {
        $this->chatService->trackRoomActivity($roomId, $action, $userId);
    }

    /**
     * Unified error logging and response
     */
    protected function logAndError(string $logMessage, \Throwable $e, array $context, string $userMessage, int $statusCode = 500): JsonResponse
    {
        if ($e instanceof ModelNotFoundException) {
            $statusCode = 404;
        }

        Log::error($logMessage, array_merge($context, [
            'error' => $e->getMessage(),
        ]));

        return $this->error($userMessage, [], $statusCode);
    }

    /**
     * Normalize room ID
     */
    protected function normalizeRoomId($roomId): int
    {
        return (int) $roomId;
    }

    /**
     * Get active room or throw 404
     */
    protected function findActiveRoom(int $roomId): ChatRoom
    {
        return ChatRoom::active()->findOrFail($roomId);
    }

    /**
     * Ensure user has joined the room
     */
    protected function ensureUserInRoom(int $roomId, int $userId, string $message, int $statusCode = 403): ?JsonResponse
    {
        if (! $this->isUserInRoom($roomId, $userId)) {
            return $this->error($message, [], $statusCode);
        }

        return null;
    }

    /**
     * Get current moderator (authenticated user)
     */
    protected function getModerator(): User
    {
        return Auth::user();
    }

    /**
     * Ensure moderator has permission to moderate the room
     */
    protected function ensureCanModerate(User $moderator, ChatRoom $room, string $message): ?JsonResponse
    {
        if (! $moderator->canModerate($room)) {
            return $this->error($message, [], 403);
        }

        return null;
    }

    /**
     * Prevent self-moderation
     */
    protected function ensureNotSelfModeration(int $moderatorId, int $targetUserId, string $message): ?JsonResponse
    {
        if ($targetUserId === $moderatorId) {
            return $this->error($message, [], 422);
        }

        return null;
    }

    /**
     * Get room user record
     */
    protected function findRoomUser(int $roomId, int $userId): ?ChatRoomUser
    {
        return ChatRoomUser::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Parse moderation filters from request
     *
     * @return array{per_page:int, action_type:?string, target_user_id:mixed}
     */
    protected function parseModerationFilters(Request $request): array
    {
        if ($request instanceof GetModerationActionsRequest) {
            return $request->validatedFilters();
        }

        return [
            'per_page' => (int) $request->get('per_page', 20),
            'action_type' => $request->get('action_type'),
            'target_user_id' => $request->get('target_user_id'),
        ];
    }
}
