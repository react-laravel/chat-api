<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property mixed $id
 * @property mixed $message_id
 * @property mixed $room_id
 * @property mixed $user_id
 * @property mixed $status
 * @property ChatMessage $message
 * @property ChatRoom $room
 */
class ChatMessageReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'reported_by',
        'room_id',
        'report_type',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'message_id' => 'integer',
        'reported_by' => 'integer',
        'room_id' => 'integer',
        'reviewed_by' => 'integer',
    ];

    /**
     * Report type constants
     */
    const TYPE_INAPPROPRIATE_CONTENT = 'inappropriate_content';

    const TYPE_SPAM = 'spam';

    const TYPE_HARASSMENT = 'harassment';

    const TYPE_HATE_SPEECH = 'hate_speech';

    const TYPE_VIOLENCE = 'violence';

    const TYPE_SEXUAL_CONTENT = 'sexual_content';

    const TYPE_MISINFORMATION = 'misinformation';

    const TYPE_OTHER = 'other';

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';

    const STATUS_REVIEWED = 'reviewed';

    const STATUS_RESOLVED = 'resolved';

    const STATUS_DISMISSED = 'dismissed';

    /**
     * Get the reported message.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /**
     * Get the user who reported the message.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the room where the message was reported.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the moderator who reviewed the report.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope to get pending reports.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get reviewed reports.
     */
    public function scopeReviewed($query)
    {
        return $query->where('status', self::STATUS_REVIEWED);
    }

    /**
     * Scope to get resolved reports.
     */
    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /**
     * Scope to get dismissed reports.
     */
    public function scopeDismissed($query)
    {
        return $query->where('status', self::STATUS_DISMISSED);
    }

    /**
     * Scope to get reports for a specific room.
     */
    public function scopeForRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Scope to get reports of a specific type.
     */
    public function scopeOfType($query, string $reportType)
    {
        return $query->where('report_type', $reportType);
    }

    /**
     * Scope to get reports by a specific user.
     */
    public function scopeByReporter($query, int $userId)
    {
        return $query->where('reported_by', $userId);
    }

    /**
     * Check if the report is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the report has been reviewed.
     */
    public function isReviewed(): bool
    {
        return in_array($this->status, [self::STATUS_REVIEWED, self::STATUS_RESOLVED, self::STATUS_DISMISSED]);
    }

    /**
     * Check if the report is resolved.
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Check if the report is dismissed.
     */
    public function isDismissed(): bool
    {
        return $this->status === self::STATUS_DISMISSED;
    }

    /**
     * Mark the report as reviewed.
     */
    public function markAsReviewed(int $reviewerId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_REVIEWED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Mark the report as resolved.
     */
    public function markAsResolved(int $reviewerId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_RESOLVED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Mark the report as dismissed.
     */
    public function markAsDismissed(int $reviewerId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_DISMISSED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Get the severity level of this report type.
     */
    public function getSeverityLevel(): string
    {
        switch ($this->report_type) {
            case self::TYPE_HATE_SPEECH:
            case self::TYPE_VIOLENCE:
            case self::TYPE_HARASSMENT:
                return 'high';
            case self::TYPE_INAPPROPRIATE_CONTENT:
            case self::TYPE_SEXUAL_CONTENT:
            case self::TYPE_MISINFORMATION:
                return 'medium';
            case self::TYPE_SPAM:
            case self::TYPE_OTHER:
            default:
                return 'low';
        }
    }

    /**
     * Get human-readable report type.
     */
    public function getReportTypeLabel(): string
    {
        return match ($this->report_type) {
            self::TYPE_INAPPROPRIATE_CONTENT => 'Inappropriate Content',
            self::TYPE_SPAM => 'Spam',
            self::TYPE_HARASSMENT => 'Harassment',
            self::TYPE_HATE_SPEECH => 'Hate Speech',
            self::TYPE_VIOLENCE => 'Violence/Threats',
            self::TYPE_SEXUAL_CONTENT => 'Sexual Content',
            self::TYPE_MISINFORMATION => 'Misinformation',
            default => 'Other',
        };
    }
}
