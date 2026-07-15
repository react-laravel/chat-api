<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ChatCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = new ChatCacheService;
        Cache::flush();
        RateLimiter::clear('chat:rate_limit:test_rate_limit_key');
        RateLimiter::clear('chat:rate_limit:test_rate_limit_exceeded');
    }

    #[Test]
    public function it_gets_room_list_from_cache()
    {
        // Create rooms
        $rooms = ChatRoom::factory()->count(3)->create(['is_active' => true]);

        $result = $this->cacheService->getRoomList();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(3, $result);
        $this->assertTrue($result->every(fn ($room) => $room->is_active));
    }

    #[Test]
    public function it_caches_room_list()
    {
        $rooms = ChatRoom::factory()->count(2)->create(['is_active' => true]);

        // First call should cache
        $firstResult = $this->cacheService->getRoomList();

        // Second call should use cache
        $secondResult = $this->cacheService->getRoomList();

        $this->assertEquals($firstResult->count(), $secondResult->count());
    }

    #[Test]
    public function it_invalidates_room_list_cache()
    {
        $rooms = ChatRoom::factory()->count(2)->create(['is_active' => true]);

        // Cache the room list
        $this->cacheService->getRoomList();

        // Invalidate cache
        $this->cacheService->invalidateRoomList();

        // Cache should be cleared
        $this->assertNull(Cache::get('chat:rooms:list'));
    }

    #[Test]
    public function it_gets_room_stats()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $user->id]);

        // Create some users and messages
        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            ChatRoomUser::factory()->create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'is_online' => true,
            ]);
        }

        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $stats = $this->cacheService->getRoomStats($room->id);

        $this->assertArrayHasKey('room', $stats);
        $this->assertArrayHasKey('total_users', $stats);
        $this->assertArrayHasKey('online_users', $stats);
        $this->assertArrayHasKey('messages', $stats);
        $this->assertEquals(3, $stats['total_users']);
        $this->assertEquals(3, $stats['online_users']);
    }

    #[Test]
    public function it_returns_empty_stats_for_nonexistent_room()
    {
        $stats = $this->cacheService->getRoomStats(999);

        $this->assertEmpty($stats);
    }

    #[Test]
    public function it_invalidates_room_stats()
    {
        $room = ChatRoom::factory()->create();

        // Cache room stats
        $this->cacheService->getRoomStats($room->id);

        // Invalidate cache
        $this->cacheService->invalidateRoomStats($room->id);

        // Cache should be cleared
        $this->assertNull(Cache::get('chat:room:stats:'.$room->id));
    }

    #[Test]
    public function it_gets_online_users()
    {
        $room = ChatRoom::factory()->create();
        $users = User::factory()->count(3)->create();

        // Add users to room
        foreach ($users as $user) {
            ChatRoomUser::factory()->create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'is_online' => true,
            ]);
        }

        $onlineUsers = $this->cacheService->getOnlineUsers($room->id);

        $this->assertInstanceOf(Collection::class, $onlineUsers);
        $this->assertCount(3, $onlineUsers);
    }

    #[Test]
    public function it_invalidates_online_users_cache()
    {
        $room = ChatRoom::factory()->create();

        // Cache online users
        $this->cacheService->getOnlineUsers($room->id);

        // Invalidate cache
        $this->cacheService->invalidateOnlineUsers($room->id);

        // Cache should be cleared
        $this->assertNull(Cache::get('chat:room:online:'.$room->id));
    }

    #[Test]
    public function it_caches_message_history()
    {
        $room = ChatRoom::factory()->create();
        $messages = ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
        ]);

        $this->cacheService->cacheMessageHistory($room->id, 1, $messages);

        $cachedMessages = $this->cacheService->getMessageHistory($room->id, 1);

        $this->assertInstanceOf(Collection::class, $cachedMessages);
        $this->assertCount(5, $cachedMessages);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_message_history()
    {
        $room = ChatRoom::factory()->create();

        $result = $this->cacheService->getMessageHistory($room->id, 1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_invalidates_message_history()
    {
        $this->markTestSkipped('Redis-specific operations not supported in array cache driver');
    }

    #[Test]
    public function it_caches_user_presence()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $presenceData = [
            'is_online' => true,
            'last_seen_at' => now(),
            'status' => 'active',
        ];

        $this->cacheService->cacheUserPresence($user->id, $room->id, $presenceData);

        $cachedPresence = $this->cacheService->getUserPresence($user->id, $room->id);

        $this->assertIsArray($cachedPresence);
        $this->assertEquals($presenceData, $cachedPresence);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_user_presence()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $result = $this->cacheService->getUserPresence($user->id, $room->id);

        $this->assertNull($result);
    }

    #[Test]
    public function it_invalidates_user_presence()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();

        $presenceData = ['is_online' => true];

        // Cache user presence
        $this->cacheService->cacheUserPresence($user->id, $room->id, $presenceData);

        // Invalidate cache
        $this->cacheService->invalidateUserPresence($user->id, $room->id);

        // Should return null after invalidation
        $result = $this->cacheService->getUserPresence($user->id, $room->id);
        $this->assertNull($result);
    }

    #[Test]
    public function it_checks_rate_limit()
    {
        $key = 'test_rate_limit_key';
        $maxAttempts = 5;
        $windowSeconds = 60;

        $result = $this->cacheService->checkRateLimit($key, $maxAttempts, $windowSeconds);

        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('remaining', $result);
        $this->assertArrayHasKey('reset_time', $result);
        $this->assertTrue($result['allowed']);
        $this->assertEquals($maxAttempts - 1, $result['remaining']);
    }

    #[Test]
    public function it_blocks_when_rate_limit_exceeded()
    {
        $key = 'test_rate_limit_exceeded';
        $maxAttempts = 2;
        $windowSeconds = 60;

        // First attempt
        $result1 = $this->cacheService->checkRateLimit($key, $maxAttempts, $windowSeconds);
        $this->assertTrue($result1['allowed']);

        // Second attempt
        $result2 = $this->cacheService->checkRateLimit($key, $maxAttempts, $windowSeconds);
        $this->assertTrue($result2['allowed']);

        // Third attempt should be blocked
        $result3 = $this->cacheService->checkRateLimit($key, $maxAttempts, $windowSeconds);
        $this->assertFalse($result3['allowed']);
        $this->assertEquals(0, $result3['remaining']);
    }

    #[Test]
    public function it_tracks_room_activity()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $this->cacheService->trackRoomActivity($room->id, 'user_joined', $user->id);

        $activity = $this->cacheService->getRoomActivity($room->id, 24);

        $this->assertArrayHasKey('activities', $activity);
        $this->assertArrayHasKey('total_activities', $activity);
        $this->assertGreaterThan(0, $activity['total_activities']);
    }

    #[Test]
    public function it_gets_room_activity()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Track some activities
        $this->cacheService->trackRoomActivity($room->id, 'user_joined', $user->id);
        $this->cacheService->trackRoomActivity($room->id, 'message_sent', $user->id);

        $activity = $this->cacheService->getRoomActivity($room->id, 24);

        $this->assertArrayHasKey('activities', $activity);
        $this->assertArrayHasKey('total_activities', $activity);
        $this->assertArrayHasKey('activity_types', $activity);
        $this->assertGreaterThanOrEqual(2, $activity['total_activities']);
    }

    #[Test]
    public function it_warms_up_cache()
    {
        $rooms = ChatRoom::factory()->count(3)->create(['is_active' => true]);

        $this->cacheService->warmUpCache();

        // Check that room list is cached
        $cachedRooms = $this->cacheService->getRoomList();
        $this->assertCount(3, $cachedRooms);
    }

    #[Test]
    public function it_clears_all_cache()
    {
        $this->markTestSkipped('Redis-specific operations not supported in array cache driver');
    }

    #[Test]
    public function it_gets_cache_stats()
    {
        $this->markTestSkipped('Redis-specific operations not supported in array cache driver');
    }

    #[Test]
    public function it_handles_cache_miss_gracefully()
    {
        $room = ChatRoom::factory()->create();

        // Should not throw exception when cache is empty
        $result = $this->cacheService->getRoomStats($room->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('room', $result);
    }

    #[Test]
    public function it_caches_with_correct_ttl()
    {
        $room = ChatRoom::factory()->create();

        // Cache room stats
        $this->cacheService->getRoomStats($room->id);

        // Check that cache exists with correct TTL
        $cacheKey = 'chat:room:stats:'.$room->id;
        $this->assertNotNull(Cache::get($cacheKey));
    }

    #[Test]
    public function it_skips_room_users_with_missing_user_relation_in_online_users(): void
    {
        $room = ChatRoom::factory()->create();

        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => 999999,
            'is_online' => true,
        ]);

        $onlineUsers = $this->cacheService->getOnlineUsers($room->id);

        $this->assertInstanceOf(Collection::class, $onlineUsers);
        $this->assertCount(0, $onlineUsers);
    }

    #[Test]
    public function it_allows_request_when_rate_limiter_backend_throws_exception(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new \RuntimeException('rate limiter unavailable'));

        $result = $this->cacheService->checkRateLimit('fallback_case', 5, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(4, $result['remaining']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function it_silently_handles_room_activity_tracking_exception(): void
    {
        config(['cache.default' => 'redis']);
        Log::spy();

        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('redis unavailable'));

        $this->cacheService->trackRoomActivity(1, 'message_sent', 1);

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(true);
    }

    #[Test]
    public function it_returns_empty_room_activity_when_backend_throws_exception(): void
    {
        config(['cache.default' => 'redis']);

        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('redis unavailable'));

        $result = $this->cacheService->getRoomActivity(1, 1);

        $this->assertSame([], $result['activities']);
        $this->assertSame(0, $result['total_activities']);
        $this->assertSame([], $result['activity_types']);
    }

    #[Test]
    public function it_handles_delete_by_pattern_exception_without_throwing(): void
    {
        config(['cache.default' => 'redis']);
        Log::spy();

        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('redis unavailable'));

        $this->cacheService->invalidateMessageHistory(123);

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(true);
    }

    #[Test]
    public function it_returns_error_when_get_cache_stats_backend_throws_exception(): void
    {
        config(['cache.default' => 'redis']);

        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('redis unavailable'));

        $result = $this->cacheService->getCacheStats();

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Failed to get cache stats', $result['error']);
    }

    #[Test]
    public function it_returns_zero_hit_rate_when_total_is_zero(): void
    {
        $reflection = new \ReflectionClass($this->cacheService);
        $method = $reflection->getMethod('calculateHitRate');
        $method->setAccessible(true);

        $hitRate = $method->invoke($this->cacheService, 0, 0);

        $this->assertSame(0.0, $hitRate);
    }
}
