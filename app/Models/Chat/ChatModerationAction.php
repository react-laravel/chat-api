<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatModerationAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'moderator_id',
        'target_user_id',
        'message_id',
        'action_type',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'room_id' => 'integer',
        'moderator_id' => 'integer',
        'target_user_id' => 'integer',
        'message_id' => 'integer',
    ];

    /**
     * Action type constants
     */
    const ACTION_DELETE_MESSAGE = 'delete_message';

    const ACTION_MUTE_USER = 'mute_user';

    const ACTION_UNMUTE_USER = 'unmute_user';

    const ACTION_TIMEOUT_USER = 'timeout_user';

    const ACTION_BAN_USER = 'ban_user';

    const ACTION_UNBAN_USER = 'unban_user';

    const ACTION_CONTENT_FILTER = 'content_filter';

    const ACTION_SPAM_DETECTION = 'spam_detection';

    const ACTION_REPORT_MESSAGE = 'report_message';

    /**
     * Get the room this action was performed in.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the moderator who performed this action.
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /**
     * Get the target user of this action.
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get the message this action was performed on.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /**
     * Scope to get actions for a specific room.
     */
    public function scopeForRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Scope to get actions of a specific type.
     */
    public function scopeOfType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    /**
     * Scope to get actions on a specific user.
     */
    public function scopeOnUser($query, int $userId)
    {
        return $query->where('target_user_id', $userId);
    }

    /**
     * Scope to get actions by a specific moderator.
     */
    public function scopeByModerator($query, int $moderatorId)
    {
        return $query->where('moderator_id', $moderatorId);
    }

    /**
     * Check if this is an automated action.
     */
    public function isAutomated(): bool
    {
        return in_array($this->action_type, [
            self::ACTION_CONTENT_FILTER,
            self::ACTION_SPAM_DETECTION,
        ]);
    }

    /**
     * Get the severity level of this action.
     */
    public function getSeverityLevel(): string
    {
        switch ($this->action_type) {
            case self::ACTION_DELETE_MESSAGE:
            case self::ACTION_CONTENT_FILTER:
                return 'low';
            case self::ACTION_MUTE_USER:
            case self::ACTION_TIMEOUT_USER:
            case self::ACTION_SPAM_DETECTION:
                return 'medium';
            case self::ACTION_BAN_USER:
                return 'high';
            default:
                return 'low';
        }
    }
}
