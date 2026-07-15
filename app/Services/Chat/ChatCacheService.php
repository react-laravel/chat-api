<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/**
 * ChatCacheService - Handles chat caching operations
 *
 * Delegates room activity tracking to RoomActivityTracker
 */
class ChatCacheService
{
    // Cache TTL constants (in seconds)
    private const ROOM_STATS_TTL = 600; // 10 minutes

    private const ONLINE_USERS_TTL = 60; // 1 minute

    private const MESSAGE_HISTORY_TTL = 1800; // 30 minutes

    private const USER_PRESENCE_TTL = 120; // 2 minutes

    // Cache key prefixes
    private const PREFIX_ROOM_LIST = 'chat:rooms:list';

    private const PREFIX_ROOM_STATS = 'chat:room:stats:';

    private const PREFIX_ONLINE_USERS = 'chat:room:online:';

    private const PREFIX_MESSAGE_HISTORY = 'chat:room:messages:';

    private const PREFIX_USER_PRESENCE = 'chat:user:presence:';

    private const PREFIX_RATE_LIMIT = 'chat:rate_limit:';

    private const PREFIX_ROOM_ACTIVITY = 'chat:room:activity:';

    private const PREFIX_CACHE_KEYS = 'chat:cache:keys';

    public function __construct(
        private readonly RoomActivityTracker $activityTracker = new RoomActivityTracker
    ) {}

    /**
     * Get room list directly from database
     */
    public function getRoomList(?int $userId = null): Collection
    {
        $query = ChatRoom::where('is_active', true)
            ->with('creator:id,name,email')
            ->withCount([
                'users as online_count' => function ($query) {
                    $query->where('is_online', true);
                },
                'messages as message_count',
            ])
            ->orderBy('created_at', 'desc');

        if ($userId !== null) {
            $query->where(function ($q) use ($userId) {
                $q->where('is_private', false)
                    ->orWhereHas('roomUsers', fn ($q2) => $q2->where('user_id', $userId));
            });
        }

        return $query->get();
    }

    /**
     * Invalidate room list cache
     */
    public function invalidateRoomList(): void
    {
        // No longer needed since we removed room list caching
    }

    /**
     * Get cached room statistics
     */
    public function getRoomStats(int $roomId): array
    {
        $cacheKey = self::PREFIX_ROOM_STATS.$roomId;

        $stats = Cache::remember($cacheKey, self::ROOM_STATS_TTL, function () use ($roomId) {
            $room = ChatRoom::with('creator:id,name,email')->find($roomId);

            if (! $room) {
                return [];
            }

            // Single query for user counts (total + online)
            $userCounts = ChatRoomUser::where('room_id', $roomId)
                ->selectRaw('
                    COUNT(*) as total_users,
                    SUM(CASE WHEN is_online = 1 THEN 1 ELSE 0 END) as online_users
                ')
                ->first();

            // Single query for message stats (total + by type)
            $messageStats = ChatMessage::where('room_id', $roomId)
                ->selectRaw('
                    COUNT(*) as total_messages,
                    SUM(CASE WHEN message_type = ? THEN 1 ELSE 0 END) as text_messages,
                    SUM(CASE WHEN message_type = ? THEN 1 ELSE 0 END) as system_messages
                ', ['text', 'system'])
                ->first();

            $recentActivity = (int) ChatMessage::where('room_id', $roomId)
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            $lastMessage = ChatMessage::where('room_id', $roomId)->latest()->first();

            return [
                'room' => $room,
                'total_users' => (int) ($userCounts->total_users ?? 0),
                'online_users' => (int) ($userCounts->online_users ?? 0),
                'messages' => [
                    'total_messages' => (int) ($messageStats->total_messages ?? 0),
                    'text_messages' => (int) ($messageStats->text_messages ?? 0),
                    'system_messages' => (int) ($messageStats->system_messages ?? 0),
                ],
                'recent_activity_24h' => $recentActivity,
                'created_at' => $room->created_at,
                'last_activity' => $lastMessage?->created_at,
            ];
        });

        $this->registerCacheKey($cacheKey);

        return $stats;
    }

    /**
     * Invalidate room statistics cache
     */
    public function invalidateRoomStats(int $roomId): void
    {
        $cacheKey = self::PREFIX_ROOM_STATS.$roomId;
        Cache::forget($cacheKey);
        $this->unregisterCacheKey($cacheKey);
    }

    /**
     * Get cached online users for a room
     */
    public function getOnlineUsers(int $roomId): Collection
    {
        $cacheKey = self::PREFIX_ONLINE_USERS.$roomId;

        /** @var Collection<int, array<string, mixed>> $users */
        $users = Cache::remember($cacheKey, self::ONLINE_USERS_TTL, function () use ($roomId): Collection {
            $roomUsers = ChatRoomUser::where('room_id', $roomId)
                ->where('is_online', true)
                ->with('user:id,name,email')
                ->orderBy('joined_at', 'asc')
                ->get();

            $list = new Collection;
            foreach ($roomUsers as $roomUser) {
                /** @var User|null $user */
                $user = $roomUser->user;
                if ($user === null) {
                    continue;
                }
                $list->push([
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'joined_at' => $roomUser->joined_at,
                    'last_seen_at' => $roomUser->last_seen_at,
                    'is_online' => $roomUser->is_online,
                ]);
            }

            return $list;
        });

        $this->registerCacheKey($cacheKey);

        return $users;
    }

    /**
     * Invalidate online users cache for a room
     */
    public function invalidateOnlineUsers(int $roomId): void
    {
        $cacheKey = self::PREFIX_ONLINE_USERS.$roomId;
        Cache::forget($cacheKey);
        $this->unregisterCacheKey($cacheKey);
    }

    /**
     * Cache message history page
     */
    public function cacheMessageHistory(int $roomId, int $page, Collection $messages): void
    {
        $cacheKey = self::PREFIX_MESSAGE_HISTORY."{$roomId}:page:{$page}";
        Cache::put($cacheKey, $messages, self::MESSAGE_HISTORY_TTL);
        $this->registerCacheKey($cacheKey);
    }

    /**
     * Get cached message history page
     */
    public function getMessageHistory(int $roomId, int $page): ?Collection
    {
        $cacheKey = self::PREFIX_MESSAGE_HISTORY."{$roomId}:page:{$page}";

        return Cache::get($cacheKey);
    }

    /**
     * Invalidate message history cache for a room
     */
    public function invalidateMessageHistory(int $roomId): void
    {
        $pattern = self::PREFIX_MESSAGE_HISTORY."{$roomId}:page:*";
        $this->deleteByPattern($pattern);
    }

    /**
     * Cache user presence status
     */
    public function cacheUserPresence(int $userId, int $roomId, array $presenceData): void
    {
        $cacheKey = self::PREFIX_USER_PRESENCE."{$userId}:{$roomId}";
        Cache::put($cacheKey, $presenceData, self::USER_PRESENCE_TTL);
        $this->registerCacheKey($cacheKey);
    }

    /**
     * Get cached user presence status
     */
    public function getUserPresence(int $userId, int $roomId): ?array
    {
        $cacheKey = self::PREFIX_USER_PRESENCE."{$userId}:{$roomId}";

        return Cache::get($cacheKey);
    }

    /**
     * Invalidate user presence cache
     */
    public function invalidateUserPresence(int $userId, int $roomId): void
    {
        $cacheKey = self::PREFIX_USER_PRESENCE."{$userId}:{$roomId}";
        Cache::forget($cacheKey);
        $this->unregisterCacheKey($cacheKey);
    }

    /**
     * Implement rate limiting with the configured cache store
     */
    public function checkRateLimit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $cacheKey = self::PREFIX_RATE_LIMIT.$key;

        try {
            if (RateLimiter::tooManyAttempts($cacheKey, $maxAttempts)) {
                $availableIn = RateLimiter::availableIn($cacheKey);

                return [
                    'allowed' => false,
                    'attempts' => RateLimiter::attempts($cacheKey),
                    'remaining' => 0,
                    'reset_time' => now()->addSeconds($availableIn > 0 ? $availableIn : $windowSeconds),
                ];
            }

            $attempts = RateLimiter::hit($cacheKey, $windowSeconds);
            $availableIn = RateLimiter::availableIn($cacheKey);

            return [
                'allowed' => true,
                'attempts' => $attempts,
                'remaining' => max(0, $maxAttempts - $attempts),
                'reset_time' => now()->addSeconds($availableIn > 0 ? $availableIn : $windowSeconds),
            ];

        } catch (\Throwable $e) {
            return [
                'allowed' => true,
                'attempts' => 1,
                'remaining' => $maxAttempts - 1,
                'reset_time' => now()->addSeconds($windowSeconds),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Track room activity for analytics
     */
    public function trackRoomActivity(int $roomId, string $activityType, ?int $userId = null): void
    {
        $this->activityTracker->track($roomId, $activityType, $userId);
    }

    /**
     * Get room activity analytics
     */
    public function getRoomActivity(int $roomId, int $hours = 24): array
    {
        return $this->activityTracker->getActivity($roomId, $hours);
    }

    /**
     * Warm up cache for frequently accessed data
     */
    public function warmUpCache(): void
    {
        $this->getRoomList();

        $activeRooms = ChatRoom::where('is_active', true)->pluck('id');
        foreach ($activeRooms as $roomId) {
            $this->getRoomStats($roomId);
            $this->getOnlineUsers($roomId);
        }
    }

    /**
     * Clear all chat-related cache
     */
    public function clearAllCache(): void
    {
        $patterns = [
            self::PREFIX_ROOM_LIST,
            self::PREFIX_ROOM_STATS.'*',
            self::PREFIX_ONLINE_USERS.'*',
            self::PREFIX_MESSAGE_HISTORY.'*',
            self::PREFIX_USER_PRESENCE.'*',
            self::PREFIX_ROOM_ACTIVITY.'*',
        ];

        foreach ($patterns as $pattern) {
            $this->deleteByPattern($pattern);
        }
    }

    /**
     * Delete cache keys by pattern (Redis specific)
     */
    private function deleteByPattern(string $pattern): void
    {
        try {
            if (config('cache.default') === 'redis') {
                $redis = Redis::connection();
                $iterator = null;
                while ($keys = $redis->scan($iterator, $pattern)) {
                    $redis->del($keys);
                }
            } else {
                Cache::flush();
                Cache::forget(self::PREFIX_CACHE_KEYS);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to delete cache by pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        try {
            if (config('cache.default') === 'redis') {
                $redis = Redis::connection();
                $info = $redis->info();

                return [
                    'driver' => 'redis',
                    'memory_usage' => $info['used_memory_human'] ?? 'N/A',
                    'connected_clients' => $info['connected_clients'] ?? 'N/A',
                    'total_commands_processed' => $info['total_commands_processed'] ?? 'N/A',
                    'keyspace_hits' => $info['keyspace_hits'] ?? 'N/A',
                    'keyspace_misses' => $info['keyspace_misses'] ?? 'N/A',
                    'total_keys' => (int) ($info['db0']['keys'] ?? 0),
                    'hit_rate' => $this->calculateHitRate(
                        (int) ($info['keyspace_hits'] ?? 0),
                        (int) ($info['keyspace_misses'] ?? 0)
                    ),
                ];
            }

            $registeredKeys = Cache::get(self::PREFIX_CACHE_KEYS, []);
            $totalKeys = count($registeredKeys);

            return [
                'driver' => config('cache.default'),
                'total_keys' => max(1, $totalKeys),
                'memory_usage' => 'N/A',
                'hit_rate' => 0,
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to get cache stats: '.$e->getMessage(),
            ];
        }
    }

    private function registerCacheKey(string $key): void
    {
        $keys = Cache::get(self::PREFIX_CACHE_KEYS, []);
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put(self::PREFIX_CACHE_KEYS, $keys, 3600);
        }
    }

    private function unregisterCacheKey(string $key): void
    {
        $keys = Cache::get(self::PREFIX_CACHE_KEYS, []);
        $filtered = array_values(array_filter($keys, static fn ($item) => $item !== $key));
        Cache::put(self::PREFIX_CACHE_KEYS, $filtered, 3600);
    }

    private function calculateHitRate(int $hits, int $misses): float
    {
        $total = $hits + $misses;
        if ($total === 0) {
            return 0;
        }

        return $hits / $total;
    }
}
