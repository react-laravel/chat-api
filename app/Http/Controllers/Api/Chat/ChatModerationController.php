<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\MessageDeleted;
use App\Events\Chat\UserBanned;
use App\Events\Chat\UserMuted;
use App\Events\Chat\UserUnbanned;
use App\Events\Chat\UserUnmuted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\BanChatUserRequest;
use App\Http\Requests\Chat\ChatModerationReasonRequest;
use App\Http\Requests\Chat\MuteChatUserRequest;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatModerationAction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatModerationController extends Controller
{
    use ChatControllerHelpers;

    /**
     * Delete a message (admin/moderator only).
     */
    public function deleteMessage(ChatModerationReasonRequest $request, int $roomId, int $messageId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $message = ChatMessage::where('room_id', $roomId)->findOrFail($messageId);
        $moderator = $this->getModerator();
        $validated = $request->validated();
        $reason = $validated['reason'] ?? null;

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to moderate this room');
        if ($guard) {
            return $guard;
        }

        try {
            DB::beginTransaction();

            // Log the moderation action
            $moderationAction = ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderator->id,
                'target_user_id' => $message->user_id,
                'message_id' => $messageId,
                'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
                'reason' => $reason,
                'metadata' => [
                    'original_message' => $message->message,
                    'message_type' => $message->message_type,
                ],
            ]);

            // Remove message reference before deleting to avoid cascade
            $moderationAction->update(['message_id' => null]);

            // Delete the message
            $message->delete();

            DB::commit();

            // Broadcast the deletion
            broadcast(new MessageDeleted($messageId, $roomId, $moderator->id, $reason));

            return $this->success([
                'action' => 'delete_message',
                'moderator' => $moderator->name,
                'reason' => $reason,
            ], 'Message deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to delete message',
                $e,
                [
                    'room_id' => $roomId,
                    'message_id' => $messageId,
                    'moderator_id' => $moderator->id,
                ],
                'Failed to delete message'
            );
        }
    }

    /**
     * Mute a user in a room.
     */
    public function muteUser(MuteChatUserRequest $request, int $roomId, int $userId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();
        $validated = $request->validated();
        $duration = $validated['duration'] ?? null;
        $reason = $validated['reason'] ?? null;

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to moderate this room');
        if ($guard) {
            return $guard;
        }

        // Prevent self-moderation
        $guard = $this->ensureNotSelfModeration($moderator->id, $userId, 'You cannot mute yourself');
        if ($guard) {
            return $guard;
        }

        $roomUser = $this->findRoomUser($roomId, $userId);

        if (! $roomUser) {
            return $this->error('User is not in this room', [], 404);
        }

        try {
            DB::beginTransaction();

            // Mute the user
            $roomUser->mute($moderator->id, $duration, $reason);

            // Log the moderation action
            ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderator->id,
                'target_user_id' => $userId,
                'action_type' => ChatModerationAction::ACTION_MUTE_USER,
                'reason' => $reason,
                'metadata' => [
                    'duration_minutes' => $duration,
                    'muted_until' => $duration ? now()->addMinutes($duration)->toISOString() : null,
                ],
            ]);

            DB::commit();

            // Broadcast the mute action
            broadcast(new UserMuted($roomId, $userId, $moderator->id, $duration, $reason));

            return $this->success([
                'action' => 'mute_user',
                'target_user_id' => $userId,
                'moderator' => $moderator->name,
                'duration_minutes' => $duration,
                'reason' => $reason,
                'muted_until' => $duration ? now()->addMinutes($duration)->toISOString() : null,
            ], 'User muted successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to mute user',
                $e,
                [
                    'room_id' => $roomId,
                    'target_user_id' => $userId,
                    'moderator_id' => $moderator->id,
                ],
                'Failed to mute user'
            );
        }
    }

    /**
     * Unmute a user in a room.
     */
    public function unmuteUser(ChatModerationReasonRequest $request, int $roomId, int $userId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();
        $validated = $request->validated();
        $reason = $validated['reason'] ?? null;

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to moderate this room');
        if ($guard) {
            return $guard;
        }

        $roomUser = $this->findRoomUser($roomId, $userId);

        if (! $roomUser) {
            return $this->error('User is not in this room', [], 404);
        }

        if (! $roomUser->isMuted()) {
            return $this->error('User is not muted', [], 422);
        }

        try {
            DB::beginTransaction();

            // Unmute the user
            $roomUser->unmute();

            // Log the moderation action
            ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderator->id,
                'target_user_id' => $userId,
                'action_type' => ChatModerationAction::ACTION_UNMUTE_USER,
                'reason' => $reason,
            ]);

            DB::commit();

            // Broadcast the unmute action
            broadcast(new UserUnmuted($roomId, $userId, $moderator->id, $reason));

            return $this->success([
                'action' => 'unmute_user',
                'target_user_id' => $userId,
                'moderator' => $moderator->name,
                'reason' => $reason,
            ], 'User unmuted successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to unmute user',
                $e,
                [
                    'room_id' => $roomId,
                    'target_user_id' => $userId,
                    'moderator_id' => $moderator->id,
                ],
                'Failed to unmute user'
            );
        }
    }

    /**
     * Ban a user from a room.
     */
    public function banUser(BanChatUserRequest $request, int $roomId, int $userId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();
        $validated = $request->validated();
        $duration = $validated['duration'] ?? null;
        $reason = $validated['reason'] ?? null;

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to moderate this room');
        if ($guard) {
            return $guard;
        }

        // Prevent self-moderation
        $guard = $this->ensureNotSelfModeration($moderator->id, $userId, 'You cannot ban yourself');
        if ($guard) {
            return $guard;
        }

        $roomUser = $this->findRoomUser($roomId, $userId);

        if (! $roomUser) {
            return $this->error('User is not in this room', [], 404);
        }

        try {
            DB::beginTransaction();

            // Ban the user
            $roomUser->ban($moderator->id, $duration, $reason);

            // Log the moderation action
            ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderator->id,
                'target_user_id' => $userId,
                'action_type' => ChatModerationAction::ACTION_BAN_USER,
                'reason' => $reason,
                'metadata' => [
                    'duration_minutes' => $duration,
                    'banned_until' => $duration ? now()->addMinutes($duration)->toISOString() : null,
                ],
            ]);

            DB::commit();

            // Broadcast the ban action
            broadcast(new UserBanned($roomId, $userId, $moderator->id, $duration, $reason));

            return $this->success([
                'action' => 'ban_user',
                'target_user_id' => $userId,
                'moderator' => $moderator->name,
                'duration_minutes' => $duration,
                'reason' => $reason,
                'banned_until' => $duration ? now()->addMinutes($duration)->toISOString() : null,
            ], 'User banned successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to ban user',
                $e,
                [
                    'room_id' => $roomId,
                    'target_user_id' => $userId,
                    'moderator_id' => $moderator->id,
                ],
                'Failed to ban user'
            );
        }
    }

    /**
     * Unban a user from a room.
     */
    public function unbanUser(ChatModerationReasonRequest $request, int $roomId, int $userId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $moderator = $this->getModerator();
        $validated = $request->validated();
        $reason = $validated['reason'] ?? null;

        // Check if user can moderate
        $guard = $this->ensureCanModerate($moderator, $room, 'You are not authorized to moderate this room');
        if ($guard) {
            return $guard;
        }

        $roomUser = $this->findRoomUser($roomId, $userId);

        if (! $roomUser) {
            return $this->error('User is not in this room', [], 404);
        }

        if (! $roomUser->isBanned()) {
            return $this->error('User is not banned', [], 422);
        }

        try {
            DB::beginTransaction();

            // Unban the user
            $roomUser->unban();

            // Log the moderation action
            ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderator->id,
                'target_user_id' => $userId,
                'action_type' => ChatModerationAction::ACTION_UNBAN_USER,
                'reason' => $reason,
            ]);

            DB::commit();

            // Broadcast the unban action
            broadcast(new UserUnbanned($roomId, $userId, $moderator->id, $reason));

            return $this->success([
                'action' => 'unban_user',
                'target_user_id' => $userId,
                'moderator' => $moderator->name,
                'reason' => $reason,
            ], 'User unbanned successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to unban user',
                $e,
                [
                    'room_id' => $roomId,
                    'target_user_id' => $userId,
                    'moderator_id' => $moderator->id,
                ],
                'Failed to unban user'
            );
        }
    }
}
