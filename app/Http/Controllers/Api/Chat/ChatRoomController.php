<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateRoomRequest;
use App\Http\Requests\Chat\UpdateRoomRequest;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ChatRoomController extends Controller
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
     * Get all rooms
     */
    public function index(): JsonResponse
    {
        try {
            $rooms = $this->chatService->getActiveRooms($this->getCurrentUserId());

            return $this->success(['rooms' => $rooms], 'Rooms retrieved successfully');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to retrieve rooms',
                $e,
                ['user_id' => $this->getCurrentUserId()],
                'Failed to retrieve rooms'
            );
        }
    }

    /**
     * Create room
     */
    public function store(CreateRoomRequest $request): JsonResponse
    {
        try {
            $result = $this->chatService->createRoom([
                'name' => $request->name,
                'description' => $request->description,
                'is_private' => $request->boolean('is_private'),
            ], $this->getCurrentUserId());

            if (empty($result['success'])) {
                return $this->error('Failed to create room', $result['errors'] ?? []);
            }

            Log::info('Room created', [
                'room_id' => $result['room']->id,
                'room_name' => $result['room']->name,
                'created_by' => $this->getCurrentUserId(),
            ]);

            return $this->success(['room' => $result['room']], 'Room created successfully', 201);
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to create room',
                $e,
                [
                    'user_id' => $this->getCurrentUserId(),
                    'room_name' => $request->name ?? 'unknown',
                ],
                'Failed to create room'
            );
        }
    }

    /**
     * Update room
     */
    public function update(UpdateRoomRequest $request, int $roomId): JsonResponse
    {
        $resolvedRoomId = $this->normalizeRoomId($roomId);

        try {
            $result = $this->chatService->updateRoom($resolvedRoomId, $request->validated(), $this->getCurrentUserId());

            if (empty($result['success'])) {
                return $this->error('Failed to update room', $result['errors'] ?? []);
            }

            return $this->success(['room' => $result['room']->load('creator:id,name,email')], 'Room updated successfully');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to update room',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $this->getCurrentUserId()),
                'Failed to update room'
            );
        }
    }

    /**
     * Join room
     */
    public function join(string|int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId((int) $roomId);
            $result = $this->chatService->joinRoom($resolvedRoomId, $userId);

            if (empty($result['success'])) {
                return $this->error('Failed to join room', $result['errors'] ?? []);
            }

            $this->clearCacheAndLogActivity($resolvedRoomId, 'user_joined', $userId);

            Log::info('User joined room', [
                'room_id' => $resolvedRoomId,
                'user_id' => $userId,
            ]);

            return $this->success([
                'room' => $result['room'],
                'room_user' => $result['room_user'],
            ], 'Successfully joined the room');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to join room',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to join room'
            );
        }
    }

    /**
     * Leave room
     */
    public function leave(string|int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId((int) $roomId);
            $result = $this->chatService->leaveRoom($resolvedRoomId, $userId);

            if (empty($result['success'])) {
                return $this->error('Failed to leave room', $result['errors'] ?? []);
            }

            $this->clearCacheAndLogActivity($resolvedRoomId, 'user_left', $userId);

            Log::info('User left room', [
                'room_id' => $resolvedRoomId,
                'user_id' => $userId,
            ]);

            return $this->success([], $result['message'] ?? 'Left room');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to leave room',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to leave room'
            );
        }
    }

    /**
     * Delete room (creator only)
     */
    public function destroy(string|int $roomId): JsonResponse
    {
        $userId = null;
        $resolvedRoomId = null;

        try {
            [$userId, $resolvedRoomId] = $this->resolveUserAndRoomId((int) $roomId);
            $result = $this->chatService->deleteRoom($resolvedRoomId, $userId);

            if (empty($result['success'])) {
                $statusCode = (isset($result['errors']) && in_array('You do not have permission to delete this room', $result['errors'])) ? 403 : 422;

                return $this->error('Failed to delete room', $result['errors'] ?? [], $statusCode);
            }

            Log::info('Room deleted', [
                'room_id' => $resolvedRoomId,
                'deleted_by' => $userId,
            ]);

            return $this->success([], $result['message'] ?? 'Room deleted');
        } catch (\Throwable $e) {
            return $this->logAndError(
                'Failed to delete room',
                $e,
                $this->buildRoomErrorContext($resolvedRoomId, $userId),
                'Failed to delete room'
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
