<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Support\Collection;

class ChatService
{
    protected ChatMessageService $messageService;

    protected ChatRoomService $roomService;

    protected ChatPresenceService $presenceService;

    protected ChatActivityService $activityService;

    protected ChatCacheService $cacheService;

    public function __construct(
        ChatMessageService $messageService,
        ChatRoomService $roomService,
        ChatPresenceService $presenceService,
        ChatActivityService $activityService,
        ChatCacheService $cacheService
    ) {
        $this->messageService = $messageService;
        $this->roomService = $roomService;
        $this->presenceService = $presenceService;
        $this->activityService = $activityService;
        $this->cacheService = $cacheService;
    }

    // ========================================
    // Message Methods (delegated to ChatMessageService)
    // ========================================

    /**
     * Validate message
     */
    public function validateMessage(string $message): array
    {
        return $this->messageService->validateMessage($message);
    }

    /**
     * Sanitize message
     */
    public function sanitizeMessage(string $message): string
    {
        return $this->messageService->sanitizeMessage($message);
    }

    /**
     * Process mentions
     */
    public function processMentions(string $message): array
    {
        return $this->messageService->processMentions($message);
    }

    /**
     * Format message
     */
    public function formatMessage(string $message, array $mentions = []): string
    {
        return $this->messageService->formatMessage($message, $mentions);
    }

    /**
     * Get message history
     */
    public function getMessageHistory(int $roomId, ?string $cursor = null, int $limit = 50, string $direction = 'before'): array
    {
        return $this->messageService->getMessageHistory($roomId, $cursor, $limit, $direction);
    }

    /**
     * Get recent messages
     */
    public function getRecentMessages(int $roomId, int $limit = 50): Collection
    {
        return $this->messageService->getRecentMessages($roomId, $limit);
    }

    /**
     * Get message history paginated
     */
    public function getMessageHistoryPaginated(int $roomId)
    {
        return $this->messageService->getMessageHistoryPaginated($roomId);
    }

    /**
     * Process message
     */
    public function processMessage(int $roomId, int $userId, string $message, string $messageType = ChatMessage::TYPE_TEXT): array
    {
        return $this->messageService->processMessage($roomId, $userId, $message, $messageType);
    }

    /**
     * Create system message
     */
    public function createSystemMessage(int $roomId, string $message, int $systemUserId = 1): ?ChatMessage
    {
        return $this->messageService->createSystemMessage($roomId, $message, $systemUserId);
    }

    /**
     * Search messages
     */
    public function searchMessages(int $roomId, string $query, ?string $cursor = null, int $limit = 20): array
    {
        return $this->messageService->searchMessages($roomId, $query, $cursor, $limit);
    }

    /**
     * Get message stats
     */
    public function getMessageStats(int $roomId): array
    {
        return $this->messageService->getMessageStats($roomId);
    }

    // ========================================
    // Room Methods (delegated to ChatRoomService)
    // ========================================

    /**
     * Validate room data
     */
    public function validateRoomData(array $data): array
    {
        return $this->roomService->validateRoomData($data);
    }

    /**
     * Create room
     */
    public function createRoom(array $data, int $createdBy): array
    {
        return $this->roomService->createRoom($data, $createdBy);
    }

    /**
     * Check room permission
     */
    public function checkRoomPermission(int $roomId, int $userId, string $operation): bool
    {
        return $this->roomService->checkRoomPermission($roomId, $userId, $operation);
    }

    /**
     * Delete room
     */
    public function deleteRoom(int $roomId, int $userId): array
    {
        return $this->roomService->deleteRoom($roomId, $userId);
    }

    /**
     * Update room
     */
    public function updateRoom(int $roomId, array $data, int $userId): array
    {
        return $this->roomService->updateRoom($roomId, $data, $userId);
    }

    /**
     * Get room stats
     */
    public function getRoomStats(int $roomId): array
    {
        return $this->roomService->getRoomStats($roomId);
    }

    /**
     * Get active rooms. When $userId is set, only public rooms or rooms the user is a member of.
     */
    public function getActiveRooms(?int $userId = null): Collection
    {
        return $this->roomService->getActiveRooms($userId);
    }

    // ========================================
    // Presence Methods (delegated to ChatPresenceService)
    // ========================================

    /**
     * Update user status
     */
    public function updateUserStatus(int $roomId, int $userId, bool $isOnline = true): array
    {
        return $this->presenceService->updateUserStatus($roomId, $userId, $isOnline);
    }

    /**
     * Join room
     */
    public function joinRoom(int $roomId, int $userId): array
    {
        return $this->presenceService->joinRoom($roomId, $userId);
    }

    /**
     * Leave room
     */
    public function leaveRoom(int $roomId, int $userId): array
    {
        return $this->presenceService->leaveRoom($roomId, $userId);
    }

    /**
     * Get online users
     */
    public function getOnlineUsers(int $roomId): Collection
    {
        return $this->presenceService->getOnlineUsers($roomId);
    }

    /**
     * Process heartbeat
     */
    public function processHeartbeat(int $roomId, int $userId): array
    {
        return $this->presenceService->processHeartbeat($roomId, $userId);
    }

    /**
     * Cleanup inactive users
     */
    public function cleanupInactiveUsers(): array
    {
        return $this->presenceService->cleanupInactiveUsers();
    }

    // ========================================
    // Activity Methods (delegated to ChatActivityService)
    // ========================================

    /**
     * Get user activity
     */
    public function getUserActivity(int $roomId, int $hours = 24): array
    {
        return $this->activityService->getUserActivity($roomId, $hours);
    }

    /**
     * Get presence stats
     */
    public function getPresenceStats(): array
    {
        return $this->activityService->getPresenceStats();
    }

    /**
     * Track room activity
     */
    public function trackRoomActivity(int $roomId, string $action, int $userId): void
    {
        $this->activityService->trackRoomActivity($roomId, $action, $userId);
    }
}
