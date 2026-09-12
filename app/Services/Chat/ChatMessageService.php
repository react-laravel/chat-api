<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatAttachment;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatMessageService
{
    protected ChatPaginationService $paginationService;

    public function __construct(ChatPaginationService $paginationService)
    {
        $this->paginationService = $paginationService;
    }

    /**
     * Message validation rules
     */
    const MAX_MESSAGE_LENGTH = 1000;

    const MIN_MESSAGE_LENGTH = 1;

    /**
     * Pagination settings
     */
    const DEFAULT_PAGE_SIZE = 50;

    const MAX_PAGE_SIZE = 100;

    /**
     * Validate and sanitize message before processing
     */
    public function validateMessage(string $message): array
    {
        $errors = [];

        // Trim whitespace
        $message = $this->sanitizeMessage($message);
        $messageLength = mb_strlen($message);

        // Check length
        if ($messageLength < self::MIN_MESSAGE_LENGTH) {
            $errors[] = 'Message cannot be empty';
        }

        if ($messageLength > self::MAX_MESSAGE_LENGTH) {
            $errors[] = 'Message cannot exceed '.self::MAX_MESSAGE_LENGTH.' characters';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized_message' => $this->sanitizeMessage($message),
        ];
    }

    /**
     * Sanitize message content to prevent XSS and other security issues
     */
    public function sanitizeMessage(string $message): string
    {
        // Remove any HTML tags and their contents
        $message = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $message);
        $message = strip_tags($message);

        // Keep line breaks entered by the composer, including blank lines.
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/[^\S\n]+/u', ' ', $message);

        return trim($message);
    }

    /**
     * Detect and process user mentions in messages
     *
     * @return array List of mentioned users
     */
    public function processMentions(string $message): array
    {
        // Match ASCII and Unicode usernames while keeping the first-seen order.
        $pattern = '/@([\p{L}\p{N}_.-]+)/u';

        if (preg_match_all($pattern, $message, $matches)) {
            $orderedUsernames = collect($matches[1])
                ->map(fn (string $username) => $this->normalizeUsername($username))
                ->filter()
                ->unique()
                ->values();

            if ($orderedUsernames->isEmpty()) {
                return [];
            }

            // Find users by name (case-insensitive where supported) and map back to mention order.
            $users = User::whereIn(DB::raw('LOWER(name)'), $orderedUsernames->all())
                ->get(['id', 'name']);

            $usersByNormalizedName = $users->keyBy(
                fn (User $user) => $this->normalizeUsername($user->name)
            );

            $mentions = [];
            foreach ($orderedUsernames as $normalizedUsername) {
                $user = $usersByNormalizedName->get($normalizedUsername);
                if (! $user) {
                    continue;
                }

                $mentions[] = [
                    'user_id' => $user->id,
                    'username' => $user->name,
                ];
            }

            return $mentions;
        }

        return [];
    }

    /**
     * Format message with emoji and mention highlighting
     */
    public function formatMessage(string $message, array $mentions = []): string
    {
        // Process mentions - wrap with special tags for frontend highlighting
        foreach ($mentions as $mention) {
            $pattern = '/@'.preg_quote($mention['username'], '/').'/iu';
            $replacement = '<mention data-user-id="'.$mention['user_id'].'">@'.$mention['username'].'</mention>';
            $message = preg_replace($pattern, $replacement, $message);
        }

        // Convert common text emoticons to emojis
        $emoticons = [
            ':)' => '😊',
            ':(' => '😢',
            ':D' => '😃',
            ':P' => '😛',
            ':o' => '😮',
            ';)' => '😉',
            '<3' => '❤️',
            '</3' => '💔',
            ':thumbsup:' => '👍',
            ':thumbsdown:' => '👎',
            ':fire:' => '🔥',
            ':star:' => '⭐',
            ':check:' => '✅',
            ':x:' => '❌',
        ];

        foreach ($emoticons as $text => $emoji) {
            $message = str_replace($text, $emoji, $message);
        }

        return $message;
    }

    /**
     * Get message history with cursor-based pagination
     */
    public function getMessageHistory(int $roomId, ?string $cursor = null, int $limit = self::DEFAULT_PAGE_SIZE, string $direction = 'before'): array
    {
        return $this->paginationService->getMessagesCursor($roomId, $cursor, $limit, $direction);
    }

    /**
     * Get recent messages for a room (for initial load) and cache
     */
    public function getRecentMessages(int $roomId, int $limit = self::DEFAULT_PAGE_SIZE): Collection
    {
        $result = $this->paginationService->getRecentMessages($roomId, $limit);

        return $result['messages'];
    }

    /**
     * Get paginated message history for backward compatibility
     */
    public function getMessageHistoryPaginated(int $roomId): LengthAwarePaginator
    {
        $query = ChatMessage::with(ChatMessage::DISPLAY_RELATIONS)
            ->forRoom($roomId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        return $query->jsonPaginate();
    }

    /**
     * Process and create a new message
     */
    public function processMessage(int $roomId, int $userId, string $message, string $messageType = ChatMessage::TYPE_TEXT, ?int $replyToId = null, array $attachmentIds = []): array
    {
        // Validate message
        $validation = trim($message) === '' && $attachmentIds !== [] ? ['valid' => true, 'sanitized_message' => '', 'errors' => []] : $this->validateMessage($message);

        if (! $validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        $sanitizedMessage = $validation['sanitized_message'];

        // Apply content filter for text messages
        if ($messageType === ChatMessage::TYPE_TEXT && $sanitizedMessage !== '') {
            $contentFilterService = app(ContentFilterService::class);
            $filterResult = $contentFilterService->processMessage($sanitizedMessage, $userId, $roomId);

            if (! $filterResult['allowed']) {
                return [
                    'success' => false,
                    'errors' => ['Message blocked by content filter'],
                    'filter_result' => $filterResult,
                    'blocked' => true,
                ];
            }

            // If content was modified, use filtered message
            $sanitizedMessage = $filterResult['filtered_message'];
        }

        // Process mentions
        $mentions = $this->processMentions($sanitizedMessage);

        // Format message
        $formattedMessage = $this->formatMessage($sanitizedMessage, $mentions);

        try {
            // Create message
            $chatMessage = DB::transaction(function () use ($roomId, $userId, $formattedMessage, $messageType, $replyToId, $attachmentIds) {
                $attachments = ChatAttachment::whereIn('id', $attachmentIds)->where('room_id', $roomId)->where('user_id', $userId)
                    ->whereNull('message_id')->where('created_at', '>=', now()->subDay())->lockForUpdate()->get();
                if ($attachments->count() !== count($attachmentIds)) {
                    throw new \InvalidArgumentException('附件已过期或已被发送，请重新上传。');
                }
                $created = ChatMessage::create(['room_id' => $roomId, 'user_id' => $userId, 'message' => $formattedMessage,
                    'message_type' => $messageType, 'reply_to_id' => $replyToId]);
                if ($attachments->isNotEmpty()) {
                    ChatAttachment::whereIn('id', $attachments->pluck('id'))->update(['message_id' => $created->id]);
                }

                return $created;
            });

            // Load user relationship
            $chatMessage->load(ChatMessage::DISPLAY_RELATIONS);

            $result = [
                'success' => true,
                'message' => $chatMessage,
                'mentions' => $mentions,
                'original_message' => $sanitizedMessage,
            ];

            // Include filter info if applied
            if ($messageType === ChatMessage::TYPE_TEXT && isset($filterResult)) {
                $result['filter_result'] = $filterResult;
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => ['Failed to save message: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Create a system message
     */
    public function createSystemMessage(int $roomId, string $message, int $systemUserId = 1): ?ChatMessage
    {
        try {
            return ChatMessage::create([
                'room_id' => $roomId,
                'user_id' => $systemUserId,
                'message' => $this->sanitizeMessage($message),
                'message_type' => ChatMessage::TYPE_SYSTEM,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Search messages in a room using cursor-based pagination
     */
    public function searchMessages(int $roomId, string $query, ?string $cursor = null, int $limit = 20): array
    {
        $sanitizedQuery = $this->sanitizeMessage($query);

        return $this->paginationService->searchMessages($roomId, $sanitizedQuery, $cursor, $limit);
    }

    private function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /**
     * Get message statistics for a room
     */
    public function getMessageStats(int $roomId): array
    {
        $totalMessages = ChatMessage::forRoom($roomId)->count();
        $textMessages = ChatMessage::forRoom($roomId)->textMessages()->count();
        $systemMessages = ChatMessage::forRoom($roomId)->systemMessages()->count();

        $topUsers = ChatMessage::forRoom($roomId)
            ->where('message_type', ChatMessage::TYPE_TEXT)
            ->select('user_id', DB::raw('COUNT(*) as message_count'))
            ->with('user:id,name')
            ->groupBy('user_id')
            ->orderBy('message_count', 'desc')
            ->limit(5)
            ->get();

        return [
            'total_messages' => $totalMessages,
            'text_messages' => $textMessages,
            'system_messages' => $systemMessages,
            'top_users' => $topUsers,
        ];
    }
}
