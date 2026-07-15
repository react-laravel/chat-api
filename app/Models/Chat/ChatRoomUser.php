<?php

namespace App\Models\Chat;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatRoomUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'joined_at',
        'last_seen_at',
        'is_online',
        'is_muted',
        'muted_until',
        'is_banned',
        'banned_until',
        'muted_by',
        'banned_by',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_online' => 'boolean',
        'is_muted' => 'boolean',
        'muted_until' => 'datetime',
        'is_banned' => 'boolean',
        'banned_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'room_id' => 'integer',
        'user_id' => 'integer',
        'muted_by' => 'integer',
        'banned_by' => 'integer',
    ];

    /**
     * Get the chat room this relationship belongs to.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the user in this relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who muted this user.
     */
    public function mutedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_by');
    }

    /**
     * Get the user who banned this user.
     */
    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /**
     * Scope to get only online users.
     */
    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * Scope to get offline users.
     */
    public function scopeOffline($query)
    {
        return $query->where('is_online', false);
    }

    /**
     * Scope to get users in a specific room.
     */
    public function scopeInRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Scope to get users in a specific room for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get users in a specific room for a specific user.
     */
    public function scopeInRoomForUser($query, int $roomId, int $userId)
    {
        return $query->where('room_id', $roomId)->where('user_id', $userId);
    }

    /**
     * Scope to get muted users.
     */
    public function scopeMuted($query)
    {
        return $query->where('is_muted', true);
    }

    /**
     * Scope to get banned users.
     */
    public function scopeBanned($query)
    {
        return $query->where('is_banned', true);
    }

    /**
     * Scope to get users who haven't been seen for a specified time.
     */
    public function scopeInactiveSince($query, int $minutes = 5)
    {
        return $query->where('last_seen_at', '<', Carbon::now()->subMinutes($minutes));
    }

    /**
     * Mark user as online in the room.
     */
    public function markAsOnline(): void
    {
        $this->update([
            'is_online' => true,
            'last_seen_at' => Carbon::now(),
            'joined_at' => $this->joined_at ?? Carbon::now(),
        ]);
    }

    /**
     * Mark user as offline in the room.
     */
    public function markAsOffline(): void
    {
        $this->update([
            'is_online' => false,
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * Update the user's last seen timestamp.
     */
    public function updateLastSeen(): void
    {
        $this->update([
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * Check if the user has been inactive for too long.
     */
    public function isInactive($minutes = 5): bool
    {
        if (! $this->last_seen_at) {
            return true;
        }

        return $this->last_seen_at->lt(Carbon::now()->subMinutes($minutes));
    }

    /**
     * Check if the user is currently muted.
     */
    public function isMuted(): bool
    {
        if (! $this->is_muted) {
            return false;
        }

        // If there's a mute expiration time, check if it's still valid
        if ($this->muted_until && $this->muted_until->isPast()) {
            // Automatically unmute if the time has passed
            $this->update([
                'is_muted' => false,
                'muted_until' => null,
                'muted_by' => null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if the user is currently banned.
     */
    public function isBanned(): bool
    {
        if (! $this->is_banned) {
            return false;
        }

        // If there's a ban expiration time, check if it's still valid
        if ($this->banned_until && $this->banned_until->isPast()) {
            // Automatically unban if the time has passed
            $this->update([
                'is_banned' => false,
                'banned_until' => null,
                'banned_by' => null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Mute the user in this room.
     */
    public function mute($moderatorId, $duration = null, $reason = null): void
    {
        $muteData = [
            'is_muted' => true,
            'muted_by' => $moderatorId,
        ];

        if ($duration) {
            $muteData['muted_until'] = Carbon::now()->addMinutes($duration);
        }

        $this->update($muteData);
    }

    /**
     * Unmute the user in this room.
     */
    public function unmute(): void
    {
        $this->update([
            'is_muted' => false,
            'muted_until' => null,
            'muted_by' => null,
        ]);
    }

    /**
     * Ban the user from this room.
     */
    public function ban($moderatorId, $duration = null, $reason = null): void
    {
        $banData = [
            'is_banned' => true,
            'banned_by' => $moderatorId,
            'is_online' => false, // Banned users are automatically offline
        ];

        if ($duration) {
            $banData['banned_until'] = Carbon::now()->addMinutes($duration);
        }

        $this->update($banData);
    }

    /**
     * Unban the user from this room.
     */
    public function unban(): void
    {
        $this->update([
            'is_banned' => false,
            'banned_until' => null,
            'banned_by' => null,
        ]);
    }

    /**
     * Check if the user can send messages (not muted or banned).
     */
    public function canSendMessages(): bool
    {
        return ! $this->isMuted() && ! $this->isBanned();
    }

    /**
     * Find a room user by room and user ID.
     */
    public static function findByRoomAndUser(int $roomId, int $userId): ?self
    {
        return self::inRoom($roomId)->forUser($userId)->first();
    }

    /**
     * Get online count for a room.
     */
    public static function onlineCountInRoom(int $roomId): int
    {
        return self::inRoom($roomId)->online()->count();
    }
}
