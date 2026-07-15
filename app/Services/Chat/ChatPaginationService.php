<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChatPaginationService
{
    private const DEFAULT_PAGE_SIZE = 50;

    private const MAX_PAGE_SIZE = 100;

    private ?bool $supportsFullTextSearch = null;

    /**
     * Get messages using cursor-based pagination for better performance
     *
     * @param  string|null  $cursor  Base64 encoded cursor containing last message ID and timestamp
     * @param  int  $limit  Number of messages to fetch
     * @param  string  $direction  'before' for older messages, 'after' for newer messages
     */
    public function getMessagesCursor(
        int $roomId,
        ?string $cursor = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
        string $direction = 'before'
    ): array {
        // Ensure limit doesn't exceed maximum
        $limit = min($limit, self::MAX_PAGE_SIZE);

        $query = ChatMessage::with(['user:id,name,email'])
            ->where('room_id', $roomId);

        // Parse cursor if provided
        $cursorData = $this->parseCursor($cursor);

        if ($cursorData) {
            if ($direction === 'before') {
                // Get older messages (pagination backwards)
                $query->where(function (Builder $q) use ($cursorData) {
                    $q->where('created_at', '<', $cursorData['timestamp'])
                        ->orWhere(function (Builder $subQ) use ($cursorData) {
                            $subQ->where('created_at', '=', $cursorData['timestamp'])
                                ->where('id', '<', $cursorData['id']);
                        });
                })
                    ->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc');
            } else {
                // Get newer messages (real-time updates)
                $query->where(function (Builder $q) use ($cursorData) {
                    $q->where('created_at', '>', $cursorData['timestamp'])
                        ->orWhere(function (Builder $subQ) use ($cursorData) {
                            $subQ->where('created_at', '=', $cursorData['timestamp'])
                                ->where('id', '>', $cursorData['id']);
                        });
                })
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc');
            }
        } else {
            // No cursor provided, get most recent messages
            $query->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc');
        }

        // Fetch one extra to determine if there are more pages
        $messages = $query->limit($limit + 1)->get();

        $hasMore = $messages->count() > $limit;
        if ($hasMore) {
            $messages = $messages->slice(0, $limit);
        }

        // For 'before' direction, reverse the order to show chronologically
        if ($direction === 'before' && ! $cursor) {
            $messages = $messages->reverse()->values();
        }

        // Generate cursors for pagination
        $nextCursor = null;
        $prevCursor = null;

        if ($messages->isNotEmpty()) {
            if ($direction === 'before' || ! $cursor) {
                // For backward pagination or initial load
                $firstMessage = $messages->first();
                $lastMessage = $messages->last();

                $nextCursor = $hasMore ? $this->generateCursor($lastMessage->id, $lastMessage->created_at) : null;
                $prevCursor = $this->generateCursor($firstMessage->id, $firstMessage->created_at);
            } else {
                // For forward pagination (newer messages)
                $firstMessage = $messages->first();
                $lastMessage = $messages->last();

                $nextCursor = $hasMore ? $this->generateCursor($lastMessage->id, $lastMessage->created_at) : null;
                $prevCursor = $this->generateCursor($firstMessage->id, $firstMessage->created_at);
            }
        }

        return [
            'messages' => $messages->values(),
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor,
        ];
    }

    /**
     * Get recent messages for initial room load (optimized)
     */
    public function getRecentMessages(int $roomId, int $limit = self::DEFAULT_PAGE_SIZE): array
    {
        $limit = min($limit, self::MAX_PAGE_SIZE);

        // Use cursor pagination for consistency
        return $this->getMessagesCursor($roomId, null, $limit, 'before');
    }

    /**
     * Get messages after a specific message (for real-time updates)
     */
    public function getMessagesAfter(int $roomId, int $afterMessageId, int $limit = 50): Collection
    {
        $afterMessage = ChatMessage::find($afterMessageId);
        if (! $afterMessage) {
            return collect();
        }

        $cursor = $this->generateCursor($afterMessage->id, $afterMessage->created_at);
        $result = $this->getMessagesCursor($roomId, $cursor, $limit, 'after');

        return $result['messages'];
    }

    /**
     * Search messages with cursor-based pagination
     */
    public function searchMessages(
        int $roomId,
        string $searchQuery,
        ?string $cursor = null,
        int $limit = 20
    ): array {
        $searchQuery = trim($searchQuery);
        $limit = min($limit, 50); // Lower limit for search results

        if ($searchQuery === '') {
            return [
                'messages' => collect(),
                'search_query' => '',
                'has_more' => false,
                'next_cursor' => null,
            ];
        }

        $query = ChatMessage::with(['user:id,name,email'])
            ->where('room_id', $roomId)
            ->where('message_type', ChatMessage::TYPE_TEXT); // Only search text messages

        $this->applySearchConstraint($query, $searchQuery);

        // Apply cursor if provided
        $cursorData = $this->parseCursor($cursor);
        if ($cursorData) {
            $query->where(function (Builder $q) use ($cursorData) {
                $q->where('created_at', '<', $cursorData['timestamp'])
                    ->orWhere(function (Builder $subQ) use ($cursorData) {
                        $subQ->where('created_at', '=', $cursorData['timestamp'])
                            ->where('id', '<', $cursorData['id']);
                    });
            });
        }

        $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        // Fetch one extra to determine if there are more results
        $messages = $query->limit($limit + 1)->get();

        $hasMore = $messages->count() > $limit;
        if ($hasMore) {
            $messages = $messages->slice(0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && $messages->isNotEmpty()) {
            $lastMessage = $messages->last();
            $nextCursor = $this->generateCursor($lastMessage->id, $lastMessage->created_at);
        }

        return [
            'messages' => $messages->values(),
            'search_query' => $searchQuery,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Get message statistics with efficient queries
     *
     * @param  int  $days  Number of days to analyze
     */
    public function getMessageStatistics(int $roomId, int $days = 7): array
    {
        $since = now()->subDays($days);

        // Get total messages count
        $totalMessages = ChatMessage::where('room_id', $roomId)
            ->where('created_at', '>=', $since)
            ->count();

        // Get messages per day
        $dailyStats = \DB::select('
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM chat_messages 
            WHERE room_id = ? AND created_at >= ?
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ', [$roomId, $since]);

        $messagesPerDay = [];
        foreach ($dailyStats as $stat) {
            $messagesPerDay[$stat->date] = $stat->count;
        }

        // Get active users count
        $activeUsers = ChatMessage::where('room_id', $roomId)
            ->where('created_at', '>=', $since)
            ->distinct('user_id')
            ->count('user_id');

        return [
            'total_messages' => $totalMessages,
            'messages_per_day' => $messagesPerDay,
            'active_users' => $activeUsers,
        ];
    }

    /**
     * Generate a cursor string from message ID and timestamp
     *
     * @param  Carbon  $timestamp
     */
    private function generateCursor(int $messageId, $timestamp): string
    {
        $cursorData = [
            'id' => $messageId,
            'timestamp' => $timestamp->toDateTimeString(),
        ];

        return base64_encode(json_encode($cursorData));
    }

    /**
     * Parse cursor string to extract message ID and timestamp
     */
    private function parseCursor(?string $cursor): ?array
    {
        if (! $cursor) {
            return null;
        }

        try {
            $decoded = base64_decode($cursor);
            $data = json_decode($decoded, true);

            if (! isset($data['id']) || ! isset($data['timestamp'])) {
                return null;
            }

            return [
                'id' => (int) $data['id'],
                'timestamp' => Carbon::parse($data['timestamp']),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if database supports full-text search
     */
    private function supportsFullTextSearch(): bool
    {
        if ($this->supportsFullTextSearch !== null) {
            return $this->supportsFullTextSearch;
        }

        try {
            // Check if we're using MySQL and the full-text index exists
            $driver = \DB::getDriverName();
            if ($driver !== 'mysql') {
                return $this->supportsFullTextSearch = false;
            }

            // Check if full-text index exists
            $indexes = \DB::select("SHOW INDEX FROM chat_messages WHERE Key_name = 'idx_message_fulltext'");

            return $this->supportsFullTextSearch = ! empty($indexes);
        } catch (\Exception $e) {
            return $this->supportsFullTextSearch = false;
        }
    }

    /**
     * Apply a search constraint that stays compatible with MySQL full-text quirks.
     */
    private function applySearchConstraint(Builder $query, string $searchQuery): void
    {
        $likePattern = '%'.$searchQuery.'%';
        $fullTextQuery = $this->buildFullTextSearchQuery($searchQuery);

        if ($this->supportsFullTextSearch() && $fullTextQuery !== null) {
            $query->where(function (Builder $searchBuilder) use ($fullTextQuery, $likePattern) {
                $searchBuilder
                    ->whereRaw('MATCH(message) AGAINST(? IN BOOLEAN MODE)', [$fullTextQuery])
                    ->orWhere('message', 'LIKE', $likePattern);
            });

            return;
        }

        $query->where('message', 'LIKE', $likePattern);
    }

    /**
     * Build a safe boolean-mode full-text query for simple single-word searches.
     */
    private function buildFullTextSearchQuery(string $searchQuery): ?string
    {
        $trimmedQuery = trim($searchQuery);

        if ($trimmedQuery === '') {
            return null;
        }

        // Multi-word, unicode, and symbol-heavy queries are more reliable with LIKE fallback.
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $trimmedQuery)) {
            return null;
        }

        return '+'.$trimmedQuery.'*';
    }
}
