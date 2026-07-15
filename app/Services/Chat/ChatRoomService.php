<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Dogeow\PhpHelpers\CharLength;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatRoomService
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
     * Room validation rules (calculated by character: Chinese/emoji=2, number/letter=1)
     */
    const MAX_ROOM_NAME_LENGTH = 20;

    const MIN_ROOM_NAME_LENGTH = 2;

    const MAX_ROOM_DESCRIPTION_LENGTH = 500;

    /**
     * Validate room creation/update data (format validation only, not existence check)
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $excludeRoomId  When updating, exclude this room id from unique name check (unused, kept for API compatibility)
     */
    public function validateRoomData(array $data, ?int $excludeRoomId = null): array
    {
        $errors = [];
        $name = null;
        $description = $data['description'] ?? null;
        if ($description !== null && $description !== '') {
            $description = trim($description);
        } else {
            $description = null;
        }

        // Validate room name
        if (empty($data['name'])) {
            $errors[] = '房间名称是必需的';
        } else {
            $name = trim($data['name']);

            // Use character length calculation (Chinese/emoji=2, number/letter=1)
            if (CharLength::belowMin($name, self::MIN_ROOM_NAME_LENGTH)) {
                $errors[] = '房间名称至少需要'.self::MIN_ROOM_NAME_LENGTH.'个字符';
            }
            if (CharLength::exceedsMax($name, self::MAX_ROOM_NAME_LENGTH)) {
                $errors[] = '房间名称不能超过'.self::MAX_ROOM_NAME_LENGTH.'个字符(中文/emoji 算 2 个字符，数字/字母算 1 个字符)';
            }
            // Note: Duplicate name check moved to inside transaction in createRoom/updateRoom
        }

        // Validate description if provided
        if ($description !== null && mb_strlen($description, 'UTF-8') > self::MAX_ROOM_DESCRIPTION_LENGTH) {
            $errors[] = '房间描述不能超过'.self::MAX_ROOM_DESCRIPTION_LENGTH.'个字符';
        }

        $isPrivate = isset($data['is_private']) ? (bool) $data['is_private'] : false;

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized_data' => [
                'name' => isset($name) ? $this->messageService->sanitizeMessage($name) : '',
                'description' => isset($description) ? $this->messageService->sanitizeMessage($description) : null,
                'is_private' => $isPrivate,
            ],
        ];
    }

    /**
     * Create a new chat room
     */
    public function createRoom(array $data, int $createdBy): array
    {
        $validation = $this->validateRoomData($data);

        if (! $validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        try {
            $room = DB::transaction(function () use ($validation, $createdBy) {
                // Check for duplicate room name inside transaction to prevent race conditions
                $nameExists = ChatRoom::where('name', $validation['sanitized_data']['name'])
                    ->where('is_active', true)
                    ->exists();

                if ($nameExists) {
                    throw new \InvalidArgumentException('该房间名称已存在');
                }

                $room = ChatRoom::create([
                    'name' => $validation['sanitized_data']['name'],
                    'description' => $validation['sanitized_data']['description'],
                    'created_by' => $createdBy,
                    'is_active' => true,
                    'is_private' => $validation['sanitized_data']['is_private'] ?? false,
                ]);

                // Auto-add creator to room
                ChatRoomUser::create([
                    'room_id' => $room->id,
                    'user_id' => $createdBy,
                    'joined_at' => now(),
                    'is_online' => true,
                ]);

                // Create system message for room creation
                $this->messageService->createSystemMessage($room->id, "Room '{$room->name}' has been created", $createdBy);

                return $room;
            });

            return [
                'success' => true,
                'room' => $room->load('creator:id,name,email'),
            ];

        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to create room: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Check if user has permission to perform room operation
     */
    public function checkRoomPermission(int $roomId, int $userId, string $operation): bool
    {
        $room = ChatRoom::find($roomId);

        if (! $room || ! $room->is_active) {
            return false;
        }

        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        switch ($operation) {
            case 'delete':
            case 'edit':
                // Only room creator or admin can delete/edit room
                return $room->created_by === $userId || $user->hasRole('admin');

            case 'join':
                // Any authenticated user can join active room
                return true;

            case 'moderate':
                // Room creator or admin can moderate
                return $room->created_by === $userId || $user->hasRole('admin');

            default:
                return false;
        }
    }

    /**
     * Delete a chat room (with safety checks)
     */
    public function deleteRoom(int $roomId, int $userId): array
    {
        if (! $this->checkRoomPermission($roomId, $userId, 'delete')) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to delete this room'],
            ];
        }

        $room = ChatRoom::find($roomId);

        // Check if room has active users (excluding creator)
        $activeUsers = ChatRoomUser::where('room_id', $roomId)
            ->where('is_online', true)
            ->where('user_id', '!=', $userId)
            ->count();

        if ($activeUsers > 0) {
            return [
                'success' => false,
                'errors' => ['Cannot delete room with active users. Please wait for all users to leave.'],
            ];
        }

        try {
            DB::transaction(function () use ($roomId, $room, $userId) {
                // Create system message before deletion
                $this->messageService->createSystemMessage($roomId, "Room '{$room->name}' is being deleted", $userId);

                // Soft delete: mark as inactive instead of hard delete to preserve message history
                $room->update(['is_active' => false]);

                // Remove all user associations
                ChatRoomUser::where('room_id', $roomId)->delete();
            });

            return [
                'success' => true,
                'message' => 'Room deleted successfully',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to delete room: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Update room information
     */
    public function updateRoom(int $roomId, array $data, int $userId): array
    {
        if (! $this->checkRoomPermission($roomId, $userId, 'edit')) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to edit this room'],
            ];
        }

        $room = ChatRoom::find($roomId);

        // Validate new data (format validation only, duplicate check moved to inside transaction)
        $validation = $this->validateRoomData($data, $roomId);

        if (! $validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        try {
            return DB::transaction(function () use ($room, $roomId, $validation, $userId) {
                // Check for duplicate room name inside transaction (excluding current room)
                $nameExists = ChatRoom::where('name', $validation['sanitized_data']['name'])
                    ->where('is_active', true)
                    ->where('id', '!=', $roomId)
                    ->exists();

                if ($nameExists) {
                    throw new \InvalidArgumentException('该房间名称已存在');
                }

                $oldName = $room->name;

                $update = [
                    'name' => $validation['sanitized_data']['name'],
                    'description' => $validation['sanitized_data']['description'],
                ];
                if (array_key_exists('is_private', $validation['sanitized_data'])) {
                    $update['is_private'] = $validation['sanitized_data']['is_private'];
                }
                $room->update($update);

                // Create system message if name changed
                if ($oldName !== $room->name) {
                    $this->messageService->createSystemMessage($roomId, "Room renamed from '{$oldName}' to '{$room->name}'", $userId);
                }

                return [
                    'success' => true,
                    'room' => $room->fresh(),
                ];
            });
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to update room: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Get room statistics and analytics
     *
     * Delegates to ChatCacheService for core stats (cached), adds peak_hours analysis.
     */
    public function getRoomStats(int $roomId): array
    {
        $stats = $this->cacheService->getRoomStats($roomId);

        if (empty($stats)) {
            return [];
        }

        // Add peak hours analysis (not cached - real-time analytics)
        $hourExpression = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $peakHours = ChatMessage::forRoom($roomId)
            ->select(DB::raw("{$hourExpression} as hour"), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('count', 'desc')
            ->limit(3)
            ->get();

        $stats['peak_hours'] = $peakHours;

        return $stats;
    }

    /**
     * Get active rooms list with basic stats.
     * When $userId is set, only returns public rooms or rooms the user is a member of.
     */
    public function getActiveRooms(?int $userId = null): Collection
    {
        return $this->cacheService->getRoomList($userId);
    }
}
