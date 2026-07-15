<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ChatUserModerationController extends Controller
{
    use ChatControllerHelpers;

    /**
     * Get user's moderation status in a room.
     */
    public function getUserModerationStatus(int $roomId, int $userId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to view moderation status for this room');
        if ($guard) {
            return $guard;
        }

        $roomUser = $this->findRoomUser($roomId, $userId);
        if ($roomUser) {
            $roomUser->load(['user:id,name,email', 'mutedByUser:id,name', 'bannedByUser:id,name']);
        }

        if (! $roomUser) {
            return $this->error('User is not in this room', [], 404);
        }

        return $this->success([
            'user' => $roomUser->user,
            'moderation_status' => [
                'is_muted' => $roomUser->isMuted(),
                'muted_until' => $roomUser->muted_until?->toISOString(),
                'muted_by' => $roomUser->mutedByUser,
                'is_banned' => $roomUser->isBanned(),
                'banned_until' => $roomUser->banned_until?->toISOString(),
                'banned_by' => $roomUser->bannedByUser,
                'can_send_messages' => $roomUser->canSendMessages(),
            ],
        ], 'User moderation status retrieved successfully');
    }
}
