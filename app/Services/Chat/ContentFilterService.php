<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoomUser;
use Illuminate\Support\Facades\Log;

/**
 * ContentFilterService - Main service for content filtering orchestration
 *
 * Delegates to SpamDetector and InappropriateWordFilter for specific checks
 */
class ContentFilterService
{
    public function __construct(
        private readonly SpamDetector $spamDetector = new SpamDetector,
        private readonly InappropriateWordFilter $wordFilter = new InappropriateWordFilter
    ) {}

    /**
     * Check message for inappropriate content
     */
    public function checkInappropriateContent(string $message): array
    {
        return $this->wordFilter->check($message);
    }

    /**
     * Detect spam in message
     */
    public function detectSpam(string $message, int $userId, int $roomId): array
    {
        return $this->spamDetector->detect($message, $userId, $roomId);
    }

    /**
     * Process message content filtering
     */
    public function processMessage(string $message, int $userId, int $roomId): array
    {
        $result = [
            'allowed' => true,
            'filtered_message' => $message,
            'violations' => [],
            'actions_taken' => [],
            'severity' => 'none',
        ];

        // Check inappropriate content
        $contentCheck = $this->checkInappropriateContent($message);
        if ($contentCheck['has_violations']) {
            $result['violations']['content'] = $contentCheck;
            $result['filtered_message'] = $contentCheck['filtered_message'];
            $result['severity'] = $this->mergeSeverity($result['severity'], $contentCheck['severity']);

            if ($contentCheck['action_required']) {
                $result['allowed'] = false;
                $result['actions_taken'][] = 'message_blocked';

                $this->logModerationAction($roomId, $userId, null, ChatModerationAction::ACTION_CONTENT_FILTER, [
                    'original_message' => $message,
                    'violations' => $contentCheck['violations'],
                    'severity' => $contentCheck['severity'],
                ]);
            }
        }

        // Check spam
        $spamCheck = $this->detectSpam($message, $userId, $roomId);
        if ($spamCheck['is_spam']) {
            $result['violations']['spam'] = $spamCheck;

            if ($spamCheck['action_required']) {
                $result['allowed'] = false;
                $result['actions_taken'][] = 'spam_blocked';

                $this->logModerationAction($roomId, $userId, null, ChatModerationAction::ACTION_SPAM_DETECTION, [
                    'message' => $message,
                    'violations' => $spamCheck['violations'],
                    'severity' => $spamCheck['severity'],
                ]);

                if ($spamCheck['severity'] === 'high') {
                    $this->autoMuteUser($userId, $roomId, 'Automatic mute for spam detection');
                    $result['actions_taken'][] = 'user_auto_muted';
                }
            }

            $result['severity'] = $this->mergeSeverity($result['severity'], $spamCheck['severity']);
        }

        return $result;
    }

    /**
     * Auto mute user for violations
     */
    private function autoMuteUser(int $userId, int $roomId, string $reason, int $durationMinutes = 10): bool
    {
        try {
            $roomUser = ChatRoomUser::inRoom($roomId)->forUser($userId)->first();

            if ($roomUser) {
                $roomUser->update([
                    'is_muted' => true,
                    'muted_until' => now()->addMinutes($durationMinutes),
                    'muted_by' => 1,
                ]);

                $this->logModerationAction($roomId, $userId, 1, ChatModerationAction::ACTION_MUTE_USER, [
                    'duration_minutes' => $durationMinutes,
                    'auto_action' => true,
                    'reason' => $reason,
                ]);

                return true;
            }
        } catch (\Exception $e) {
            Log::error('自动禁言用户失败', [
                'user_id' => $userId,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Log moderation action
     */
    private function logModerationAction(int $roomId, int $targetUserId, ?int $moderatorId, string $actionType, array $metadata = []): void
    {
        try {
            ChatModerationAction::create([
                'room_id' => $roomId,
                'moderator_id' => $moderatorId ?? 1,
                'target_user_id' => $targetUserId,
                'action_type' => $actionType,
                'reason' => $metadata['reason'] ?? 'Automated content filtering',
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('记录内容审核操作失败', [
                'room_id' => $roomId,
                'target_user_id' => $targetUserId,
                'action_type' => $actionType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get content filter statistics
     */
    public function getFilterStats(?int $roomId = null, int $days = 7): array
    {
        $query = ChatModerationAction::where('created_at', '>=', now()->subDays($days))
            ->whereIn('action_type', [
                ChatModerationAction::ACTION_CONTENT_FILTER,
                ChatModerationAction::ACTION_SPAM_DETECTION,
            ]);

        if ($roomId) {
            $query->where('room_id', $roomId);
        }

        $actions = $query->get();

        $stats = [
            'total_actions' => $actions->count(),
            'content_filter_actions' => $actions->where('action_type', ChatModerationAction::ACTION_CONTENT_FILTER)->count(),
            'spam_detection_actions' => $actions->where('action_type', ChatModerationAction::ACTION_SPAM_DETECTION)->count(),
            'severity_breakdown' => [
                'low' => 0,
                'medium' => 0,
                'high' => 0,
            ],
            'top_violations' => [],
            'affected_users' => $actions->pluck('target_user_id')->unique()->count(),
            'period_days' => $days,
        ];

        foreach ($actions as $action) {
            $severity = $action->metadata['severity'] ?? 'low';
            if (isset($stats['severity_breakdown'][$severity])) {
                $stats['severity_breakdown'][$severity]++;
            }
        }

        $violationTypes = [];
        foreach ($actions as $action) {
            if (isset($action->metadata['violations'])) {
                foreach ($action->metadata['violations'] as $violation) {
                    $type = $violation['type'] ?? 'unknown';
                    $violationTypes[$type] = ($violationTypes[$type] ?? 0) + 1;
                }
            }
        }

        arsort($violationTypes);
        $stats['top_violations'] = array_slice($violationTypes, 0, 10, true);

        return $stats;
    }

    private function mergeSeverity(string $currentSeverity, string $nextSeverity): string
    {
        return $this->severityRank($nextSeverity) > $this->severityRank($currentSeverity)
            ? $nextSeverity
            : $currentSeverity;
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
