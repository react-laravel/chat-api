<?php

namespace App\Models\Chat;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property mixed $id
 * @property mixed $room_id
 * @property mixed $user_id
 * @property mixed $message
 * @property mixed $message_type
 * @property User $user
 * @property Carbon|null $deleted_at
 */
class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'room_id',
        'user_id',
        'message',
        'message_type',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'room_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Message type constants
     */
    const TYPE_TEXT = 'text';

    const TYPE_SYSTEM = 'system';

    /**
     * Get the chat room this message belongs to.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the user who sent this message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get only text messages.
     */
    public function scopeTextMessages($query)
    {
        return $query->where('message_type', self::TYPE_TEXT);
    }

    /**
     * Scope to get only system messages.
     */
    public function scopeSystemMessages($query)
    {
        return $query->where('message_type', self::TYPE_SYSTEM);
    }

    /**
     * Scope to get messages for a specific room.
     */
    public function scopeForRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Check if this is a text message.
     */
    public function isTextMessage(): bool
    {
        return $this->message_type === self::TYPE_TEXT;
    }

    /**
     * Check if this is a system message.
     */
    public function isSystemMessage(): bool
    {
        return $this->message_type === self::TYPE_SYSTEM;
    }
}
