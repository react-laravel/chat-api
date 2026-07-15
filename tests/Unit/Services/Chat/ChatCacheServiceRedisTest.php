<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\ChatCacheService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class ChatCacheServiceRedisTest extends TestCase
{
    protected ChatCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure service uses Redis code paths
        Config::set('cache.default', 'redis');

        $this->cacheService = new ChatCacheService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_track_room_activity_uses_redis_list_operations_and_trims()
    {
        $roomId = 42;
        $userId = 7;
        $activityType = 'message_sent';

        // Prepare a mock Redis connection object
        $redisMock = Mockery::mock();
        // Expect lpush with a key that contains prefix and room id, and a json payload
        $redisMock->shouldReceive('lpush')->once()->withArgs(function ($key, $payload) use ($roomId, $userId, $activityType) {
            // Key should contain the room id and date hour string
            if (strpos($key, "chat:room:activity:{$roomId}:") === false) {
                return false;
            }

            $decoded = json_decode($payload, true);

            return is_array($decoded)
                && $decoded['type'] === $activityType
                && $decoded['user_id'] === $userId
                && isset($decoded['timestamp']);
        })->andReturnTrue();

        // Expect expire and ltrim to be called
        $redisMock->shouldReceive('expire')->once()->andReturnTrue();
        $redisMock->shouldReceive('ltrim')->once()->andReturnTrue();

        // When Redis::connection() is called, return our mock (allow multiple calls)
        Redis::shouldReceive('connection')->andReturn($redisMock);

        // Call the method under test
        $this->cacheService->trackRoomActivity($roomId, $activityType, $userId);

        // Basic assertion to avoid risky-test warning; mock expectations validate behavior
        $this->assertTrue(true);
    }

    public function test_get_room_activity_reads_from_redis_and_aggregates()
    {
        $roomId = 101;

        // Prepare hourly keys; the service will try to read up to $hours entries (we'll pass 3)
        // We'll stub lrange to return activity entries for two specific hours and empty for others.
        $redisMock = Mockery::mock();

        // Capture any lrange calls and return sample data for matching keys
        $redisMock->shouldReceive('lrange')
            ->withArgs(function ($key, $start, $end) use ($roomId) {
                return strpos($key, "chat:room:activity:{$roomId}:") === 0 && $start === 0 && $end === -1;
            })
            ->andReturnUsing(function ($key) {
                // Return different payloads depending on hour suffix to simulate multiple hours
                if (str_ends_with($key, date('Y-m-d-H'))) {
                    // Latest hour: two activities
                    return [
                        json_encode(['type' => 'message_sent', 'user_id' => 1, 'timestamp' => time()]),
                        json_encode(['type' => 'user_joined', 'user_id' => 2, 'timestamp' => time() - 10]),
                    ];
                }

                // Older hour: one activity
                return [
                    json_encode(['type' => 'message_sent', 'user_id' => 3, 'timestamp' => time() - 3600]),
                ];
            });

        Redis::shouldReceive('connection')->andReturn($redisMock);

        $activity = $this->cacheService->getRoomActivity($roomId, 2);

        $this->assertIsArray($activity);
        $this->assertArrayHasKey('activities', $activity);
        $this->assertArrayHasKey('total_activities', $activity);
        $this->assertArrayHasKey('activity_types', $activity);
        $this->assertGreaterThanOrEqual(2, $activity['total_activities']);
        $this->assertArrayHasKey('message_sent', $activity['activity_types']);
    }

    public function test_invalidate_message_history_deletes_keys_by_pattern_via_redis()
    {
        // Use a mock room ID instead of database
        $roomId = 123;

        $pattern = "chat:room:messages:{$roomId}:page:*";

        $redisMock = Mockery::mock();

        $matchedKeys = [
            "chat:room:messages:{$roomId}:page:1",
            "chat:room:messages:{$roomId}:page:2",
        ];

        // scan($iterator, $pattern) returns keys on first call, empty array on second to end the loop
        // Note: Mockery passes arguments by value, so $iterator is always null. We track state via closure.
        $scanCallCount = 0;
        $redisMock->shouldReceive('scan')
            ->andReturnUsing(function ($iterator) use ($matchedKeys, &$scanCallCount) {
                $scanCallCount++;

                return $scanCallCount === 1 ? $matchedKeys : [];
            });

        // Expect del to be called with the list of keys
        $redisMock->shouldReceive('del')->once()->with($matchedKeys)->andReturn(2);

        Redis::shouldReceive('connection')->andReturn($redisMock);

        // Call public method that triggers deleteByPattern internally
        $this->cacheService->invalidateMessageHistory($roomId);

        // Basic assertion to avoid risky-test warning; mock expectations validate behavior
        $this->assertTrue(true);
    }

    public function test_get_cache_stats_returns_redis_info_when_redis_driver()
    {
        $redisMock = Mockery::mock();

        $sampleInfo = [
            'used_memory_human' => '1.00M',
            'connected_clients' => 5,
            'total_commands_processed' => 12345,
            'keyspace_hits' => 50,
            'keyspace_misses' => 10,
            'db0' => ['keys' => 7],
        ];

        $redisMock->shouldReceive('info')->once()->andReturn($sampleInfo);

        Redis::shouldReceive('connection')->andReturn($redisMock);

        $stats = $this->cacheService->getCacheStats();

        $this->assertIsArray($stats);
        $this->assertSame('redis', $stats['driver']);
        $this->assertEquals('1.00M', $stats['memory_usage']);
        $this->assertEquals(5, $stats['connected_clients']);
        $this->assertEquals(12345, $stats['total_commands_processed']);
        $this->assertArrayHasKey('hit_rate', $stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertEquals(7, $stats['total_keys']);
    }
}
