<?php

namespace App\Services\Chat;

/**
 * InappropriateWordFilter - Handles inappropriate content filtering
 */
class InappropriateWordFilter
{
    /**
     * Inappropriate words list (basic implementation)
     * Production should store in database or external service
     */
    private const INAPPROPRIATE_WORDS = [
        'stupid',
        'spam',
        'hate',
        'violence',
    ];

    /**
     * Word replacements for filtered content
     */
    private const WORD_REPLACEMENTS = [
        'spam' => '****',
        'stupid' => '[filtered]',
        'hate' => '[filtered]',
        'violence' => '[filtered]',
    ];

    /**
     * High severity words
     */
    private const HIGH_SEVERITY_WORDS = ['hate', 'violence'];

    /**
     * Check message for inappropriate content
     */
    public function check(string $message): array
    {
        $violations = [];
        $severity = 'low';
        $filteredMessage = $message;
        $lowerMessage = strtolower($message);

        foreach (self::INAPPROPRIATE_WORDS as $word) {
            $wordLower = strtolower($word);
            if (strpos($lowerMessage, $wordLower) !== false) {
                $wordSeverity = $this->getWordSeverity($word);
                $violations[] = [
                    'type' => 'inappropriate_word',
                    'word' => $word,
                    'severity' => $wordSeverity,
                ];

                if (array_key_exists($word, self::WORD_REPLACEMENTS)) {
                    $filteredMessage = str_ireplace($word, self::WORD_REPLACEMENTS[$word], $filteredMessage);
                }

                if ($wordSeverity === 'high' || ($wordSeverity === 'medium' && $severity === 'low')) {
                    $severity = $wordSeverity;
                }
            }
        }

        return [
            'has_violations' => ! empty($violations),
            'violations' => $violations,
            'severity' => $severity,
            'filtered_message' => $filteredMessage,
            'action_required' => $severity === 'high' || count($violations) >= 3,
        ];
    }

    /**
     * Get severity level for a word
     */
    public function getWordSeverity(string $word): string
    {
        if (in_array($word, self::HIGH_SEVERITY_WORDS, true)) {
            return 'high';
        }

        return 'low';
    }

    /**
     * Get all inappropriate words
     */
    public function getInappropriateWords(): array
    {
        return self::INAPPROPRIATE_WORDS;
    }

    /**
     * Get replacement for a word
     */
    public function getReplacement(string $word): ?string
    {
        return self::WORD_REPLACEMENTS[$word] ?? null;
    }
}
