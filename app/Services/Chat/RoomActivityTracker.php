<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * RoomActivityTracker - Handles room activity tracking and analytics
 */
class RoomActivityTracker
{
    /** Activity cache TTL (24 hours) */
    private const ACTIVITY_TTL = 86400;

    /** Maximum activities per hour */
    private const MAX_ACTIVITIES_PER_HOUR = 1000;

    /** Maximum activities to return */
    private const MAX_ACTIVITIES_RETURN = 500;

    /**
     * Track room activity
     */
    public function track(int $roomId, string $activityType, ?int $userId = null): void
    {
        $cacheKey = $this->getCacheKey($roomId);

        try {
            $activityData = [
                'type' => $activityType,
                'user_id' => $userId,
                'timestamp' => now()->timestamp,
            ];

            if (config('cache.default') === 'redis') {
                $redis = Redis::connection();
                $redis->lpush($cacheKey, json_encode($activityData));
                $redis->expire($cacheKey, self::ACTIVITY_TTL);

                // Keep only last 1000 activities per hour
                $redis->ltrim($cacheKey, 0, self::MAX_ACTIVITIES_PER_HOUR - 1);
            } else {
                $activities = Cache::get($cacheKey, []);
                array_unshift($activities, $activityData);
                $activities = array_slice($activities, 0, self::MAX_ACTIVITIES_PER_HOUR);
                Cache::put($cacheKey, $activities, self::ACTIVITY_TTL);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to track room activity', [
                'room_id' => $roomId,
                'activity_type' => $activityType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get room activity analytics
     */
    public function getActivity(int $roomId, int $hours = 24): array
    {
        try {
            $activities = [];

            // Get activities for the last N hours
            for ($i = 0; $i < $hours; $i++) {
                $hour = now()->subHours($i)->format('Y-m-d-H');
                $cacheKey = $this->getCacheKey($roomId, $hour);

                if (config('cache.default') === 'redis') {
                    $redis = Redis::connection();
                    $hourlyActivities = $redis->lrange($cacheKey, 0, -1);
                    foreach ($hourlyActivities as $activity) {
                        $activities[] = json_decode($activity, true);
                    }
                } else {
                    $hourlyActivities = Cache::get($cacheKey, []);
                    foreach ($hourlyActivities as $activity) {
                        $activities[] = $activity;
                    }
                }
            }

            // Sort by timestamp descending
            usort($activities, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            $activities = array_slice($activities, 0, self::MAX_ACTIVITIES_RETURN);
            $activityTypes = [];
            foreach ($activities as $activity) {
                $type = $activity['type'] ?? 'unknown';
                $activityTypes[$type] = ($activityTypes[$type] ?? 0) + 1;
            }

            return [
                'activities' => $activities,
                'total_activities' => count($activities),
                'activity_types' => $activityTypes,
            ];

        } catch (\Exception $e) {
            return [
                'activities' => [],
                'total_activities' => 0,
                'activity_types' => [],
            ];
        }
    }

    /**
     * Generate cache key for room activity
     */
    private function getCacheKey(int $roomId, ?string $hour = null): string
    {
        $hour = $hour ?? date('Y-m-d-H');

        return "chat:room:activity:{$roomId}:{$hour}";
    }
}
