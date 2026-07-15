<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatMessageController extends Controller
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
     * Get room messages (paginated)
     */
    public function index(int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $room = $this->findActiveRoom($resolvedRoomId);

            $guard = $this->ensureUserInRoom($resolvedRoomId, $userId, 'You must join the room to view messages');
            if ($guard) {
                return $guard;
            }

            $paginated = $this->chatService->getMessageHistoryPaginated($resolvedRoomId);

            return response()->json($paginated);
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to retrieve messages',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to retrieve messages'
            );
        }
    }

    /**
     * Send message
     */
    public function store(SendMessageRequest $request, int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $room = $this->findActiveRoom($resolvedRoomId);

            $roomUser = $this->fetchRoomUser($resolvedRoomId, $userId);
            if (! $roomUser || ! $roomUser->is_online) {
                return $this->error('You must be online in the room to send messages', [], 403);
            }

            $perm = $this->checkUserPermission($roomUser, $room);
            if (! $perm['allowed']) {
                return $this->error($perm['message'], [], 403);
            }

            $result = $this->chatService->processMessage(
                $resolvedRoomId,
                $userId,
                $request->message,
                $request->message_type ?? ChatMessage::TYPE_TEXT
            );

            if (empty($result['success'])) {
                $errors = $result['errors'] ?? [];
                $message = is_array($errors) && $errors !== [] ? $errors[0] : 'Failed to send message';

                return $this->error($message, ['errors' => $errors]);
            }

            $roomUser->updateLastSeen();

            $this->clearRoomCache($resolvedRoomId);
            $this->cacheService->invalidateMessageHistory($resolvedRoomId);
            $this->logRoomActivity($resolvedRoomId, 'message_sent', $userId);

            broadcast(new MessageSent($result['message']));

            Log::info('Message sent', [
                'message_id' => $result['message']->id,
                'room_id' => $resolvedRoomId,
                'user_id' => $userId,
                'message_type' => $result['message']->message_type,
            ]);

            return $this->success([
                'data' => new ChatMessageResource($result['message']),
                'mentions' => $result['mentions'] ?? [],
            ], 'Message sent successfully', 201);
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to send message',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to send message'
            );
        }
    }

    /**
     * Delete message (admin)
     */
    public function destroy(int $roomId, int $messageId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId($roomId);
            $messageId = (int) $messageId;

            $room = $this->findActiveRoom($resolvedRoomId);
            $message = ChatMessage::where('room_id', $resolvedRoomId)->findOrFail($messageId);

            try {
                $this->authorize('delete', $message);
            } catch (AuthorizationException $e) {
                return $this->error('You are not authorized to delete this message', [], 403);
            }

            DB::beginTransaction();
            $deletedMessageId = $message->id;
            $deletedRoomId = $message->room_id;
            $message->delete();
            DB::commit();

            broadcast(new MessageDeleted($deletedMessageId, $deletedRoomId, $userId));

            Log::info('Message deleted', [
                'message_id' => $deletedMessageId,
                'room_id' => $deletedRoomId,
                'deleted_by' => $userId,
            ]);

            return $this->success([], 'Message deleted successfully');
        } catch (AuthorizationException $e) {
            DB::rollBack();

            return $this->error('You are not authorized to delete this message', [], 403);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->logAndError(
                'Failed to delete message',
                $e,
                [
                    'message_id' => $messageId,
                    ...$this->buildRoomErrorContext($resolvedRoomId, $userId),
                ],
                'Failed to delete message'
            );
        }
    }

    /**
     * Check user permission (mute/ban). Admin or room owner is not affected by mute/ban.
     */
    private function checkUserPermission(ChatRoomUser $roomUser, ChatRoom $room): array
    {
        $user = auth()->user();
        if ($user && $user->canModerate($room)) {
            return ['allowed' => true];
        }

        if (! $roomUser->canSendMessages()) {
            if ($roomUser->isBanned()) {
                $msg = 'You are banned from this room';
                if ($roomUser->banned_until) {
                    $msg .= ' until '.$roomUser->banned_until->format('Y-m-d H:i:s');
                }

                return ['allowed' => false, 'message' => $msg];
            }
            if ($roomUser->isMuted()) {
                $msg = 'You are muted in this room';
                if ($roomUser->muted_until) {
                    $msg .= ' until '.$roomUser->muted_until->format('Y-m-d H:i:s');
                }

                return ['allowed' => false, 'message' => $msg];
            }
        }

        return ['allowed' => true];
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
