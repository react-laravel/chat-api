<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use Illuminate\Support\Facades\Cache;

/**
 * SpamDetector - Handles spam detection logic for chat messages
 */
class SpamDetector
{
    /** Spam message frequency limit (per minute) */
    private const SPAM_MESSAGE_LIMIT = 5;

    /** Duplicate message limit */
    private const SPAM_DUPLICATE_LIMIT = 3;

    /** Excessive caps threshold (70% uppercase) */
    private const SPAM_CAPS_THRESHOLD = 0.7;

    /** Character repetition threshold (50%) */
    private const SPAM_REPETITION_THRESHOLD = 0.5;

    /**
     * Detect spam patterns in a message
     */
    public function detect(string $message, int $userId, int $roomId): array
    {
        $violations = [];
        $severity = 'low';

        // Check message frequency
        $frequencyCheck = $this->checkMessageFrequency($userId, $roomId);
        if ($frequencyCheck['is_spam']) {
            $violations[] = [
                'type' => 'high_frequency',
                'details' => $frequencyCheck,
                'severity' => 'high',
            ];
            $severity = 'high';
        }

        // Check duplicate messages
        $duplicateCheck = $this->checkDuplicateMessages($message, $userId, $roomId);
        if ($duplicateCheck['is_spam']) {
            $violations[] = [
                'type' => 'duplicate_message',
                'details' => $duplicateCheck,
                'severity' => 'medium',
            ];
            if ($severity === 'low') {
                $severity = 'medium';
            }
        }

        // Check excessive uppercase
        $capsCheck = $this->checkExcessiveCaps($message);
        if ($capsCheck['is_spam']) {
            $violations[] = [
                'type' => 'excessive_caps',
                'details' => $capsCheck,
                'severity' => 'low',
            ];
        }

        // Check character repetition
        $repetitionCheck = $this->checkCharacterRepetition($message);
        if ($repetitionCheck['is_spam']) {
            $violations[] = [
                'type' => 'character_repetition',
                'details' => $repetitionCheck,
                'severity' => 'low',
            ];
        }

        // Check URL spam
        $urlCheck = $this->checkUrlSpam($message);
        if ($urlCheck['is_spam']) {
            $violations[] = [
                'type' => 'url_spam',
                'details' => $urlCheck,
                'severity' => 'medium',
            ];
            if ($severity === 'low') {
                $severity = 'medium';
            }
        }

        return [
            'is_spam' => ! empty($violations),
            'violations' => $violations,
            'severity' => $severity,
            'action_required' => $severity === 'high' || count($violations) >= 2,
        ];
    }

    /**
     * Check message frequency for spam detection
     */
    public function checkMessageFrequency(int $userId, int $roomId): array
    {
        $cacheKey = "chat_message_frequency_{$userId}_{$roomId}";
        $messages = Cache::get($cacheKey, []);

        // Clean up messages older than 1 minute
        $oneMinuteAgo = now()->subMinute()->timestamp;
        $messages = array_filter($messages, static function ($timestamp) use ($oneMinuteAgo) {
            return $timestamp > $oneMinuteAgo;
        });

        // Add current message timestamp
        $messages[] = now()->timestamp;

        // Store back to cache, expires in 5 minutes
        Cache::put($cacheKey, $messages, 300);

        $messageCount = count($messages);

        return [
            'is_spam' => $messageCount > self::SPAM_MESSAGE_LIMIT,
            'message_count' => $messageCount,
            'limit' => self::SPAM_MESSAGE_LIMIT,
            'time_window' => '1 minute',
        ];
    }

    /**
     * Check for duplicate messages
     */
    public function checkDuplicateMessages(string $message, int $userId, int $roomId): array
    {
        $recentMessages = ChatMessage::where('user_id', $userId)
            ->where('room_id', $roomId)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->pluck('message')
            ->toArray();

        $messageHash = md5(strtolower(trim($message)));
        $duplicateCount = count(array_filter($recentMessages, static function ($recentMessage) use ($messageHash) {
            return md5(strtolower(trim($recentMessage))) === $messageHash;
        }));

        return [
            'is_spam' => $duplicateCount >= self::SPAM_DUPLICATE_LIMIT,
            'duplicate_count' => $duplicateCount,
            'limit' => self::SPAM_DUPLICATE_LIMIT,
            'time_window' => '5 minutes',
        ];
    }

    /**
     * Check for excessive uppercase letters
     */
    public function checkExcessiveCaps(string $message): array
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $message);
        $totalChars = strlen($letters);

        if ($totalChars < 10) {
            return ['is_spam' => false];
        }

        $capsChars = strlen(preg_replace('/[^A-Z]/', '', $letters));
        $capsRatio = $capsChars / $totalChars;

        return [
            'is_spam' => $capsRatio > self::SPAM_CAPS_THRESHOLD,
            'caps_ratio' => $capsRatio,
            'threshold' => self::SPAM_CAPS_THRESHOLD,
            'caps_count' => $capsChars,
            'total_letters' => $totalChars,
        ];
    }

    /**
     * Check for character repetition spam
     */
    public function checkCharacterRepetition(string $message): array
    {
        $length = strlen($message);
        if ($length < 10) {
            return ['is_spam' => false];
        }

        $repetitionCount = 0;
        $i = 0;
        while ($i < $length) {
            $char = $message[$i];
            $j = $i + 1;
            while ($j < $length && $message[$j] === $char) {
                $j++;
            }
            $consecutiveCount = $j - $i;
            if ($consecutiveCount >= 4) {
                $repetitionCount += $consecutiveCount;
            }
            $i = $j;
        }

        $repetitionRatio = $repetitionCount / $length;

        return [
            'is_spam' => $repetitionRatio > self::SPAM_REPETITION_THRESHOLD,
            'repetition_ratio' => $repetitionRatio,
            'threshold' => self::SPAM_REPETITION_THRESHOLD,
            'repetition_count' => $repetitionCount,
            'total_chars' => $length,
        ];
    }

    /**
     * Check for URL spam
     */
    public function checkUrlSpam(string $message): array
    {
        $urlPattern = '/https?:\/\/[^\s]+/i';
        preg_match_all($urlPattern, $message, $matches);
        $urlCount = count($matches[0]);

        $suspiciousPatterns = [
            '/bit\.ly/i',
            '/tinyurl/i',
            '/t\.co/i',
            '/goo\.gl/i',
            '/ow\.ly/i',
            '/free.*money/i',
            '/click.*here/i',
            '/limited.*time/i',
        ];

        $suspiciousUrls = 0;
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                $suspiciousUrls++;
            }
        }

        return [
            'is_spam' => $urlCount > 2 || $suspiciousUrls > 0,
            'url_count' => $urlCount,
            'suspicious_urls' => $suspiciousUrls,
            'urls' => $matches[0],
        ];
    }
}
