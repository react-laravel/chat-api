<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\GetModerationActionsRequest;
use App\Models\Chat\ChatModerationAction;
use Illuminate\Http\JsonResponse;

class ChatModerationLogController extends Controller
{
    use ChatControllerHelpers;

    /**
     * Get moderation actions for a room.
     */
    public function getModerationActions(GetModerationActionsRequest $request, int $roomId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to view moderation actions for this room');
        if ($guard) {
            return $guard;
        }

        $filters = $this->parseModerationFilters($request);

        $query = ChatModerationAction::forRoom($roomId)
            ->with(['moderator:id,name,email', 'targetUser:id,name,email', 'message:id,message'])
            ->orderBy('created_at', 'desc');

        if ($filters['action_type']) {
            $query->ofType($filters['action_type']);
        }

        if ($filters['target_user_id']) {
            $query->onUser($filters['target_user_id']);
        }

        $paged = $query->jsonPaginate();

        // Spatie 返回 JSON:API 格式(data/meta/links)，直接返回给客户端
        return response()->json($paged);
    }
}
