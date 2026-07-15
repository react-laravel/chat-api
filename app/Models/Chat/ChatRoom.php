<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'is_active',
        'is_private',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_private' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'created_by' => 'integer',
    ];

    /**
     * Get the user who created this chat room.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all messages in this chat room.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    /**
     * Get all users who have joined this chat room.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_room_users', 'room_id', 'user_id')
            ->withPivot(['joined_at', 'last_seen_at', 'is_online'])
            ->withTimestamps();
    }

    /**
     * Get only online users in this chat room.
     */
    public function onlineUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('is_online', true);
    }

    /**
     * Scope to get only active chat rooms.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get room users with pivot data.
     */
    public function roomUsers(): HasMany
    {
        return $this->hasMany(ChatRoomUser::class, 'room_id');
    }

    /**
     * Get only online room users.
     */
    public function onlineRoomUsers(): HasMany
    {
        return $this->roomUsers()->where('is_online', true);
    }

    /**
     * Get the count of online users in this room.
     */
    public function getOnlineCountAttribute(): int
    {
        return $this->onlineUsers()->count();
    }

    /**
     * Get the count of total users who have joined this room.
     */
    public function getTotalUsersCountAttribute(): int
    {
        return $this->users()->count();
    }

    /**
     * Get the latest message in this room.
     */
    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class, 'room_id')->latest();
    }

    /**
     * Check if the room has any activity in the last N hours.
     */
    public function hasRecentActivity(int $hours = 24): bool
    {
        return $this->messages()
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /**
     * Check if a user is the creator of this room.
     */
    public function isCreatedBy(int $userId): bool
    {
        return $this->created_by === $userId;
    }

    /**
     * Check if a user is online in this room.
     */
    public function hasUserOnline(int $userId): bool
    {
        return $this->onlineUsers()->where('chat_users.id', $userId)->exists();
    }

    /**
     * Get room statistics.
     */
    public function getStatsAttribute(): array
    {
        return [
            'total_users' => $this->total_users_count,
            'online_users' => $this->online_count,
            'total_messages' => $this->messages()->count(),
            'recent_activity' => $this->hasRecentActivity(),
            'created_days_ago' => $this->created_at->diffInDays(now()),
        ];
    }

    /**
     * Scope to get rooms with recent activity.
     */
    public function scopeWithRecentActivity($query, int $hours = 24)
    {
        return $query->whereHas('messages', function ($q) use ($hours) {
            $q->where('created_at', '>=', now()->subHours($hours));
        });
    }

    /**
     * Scope to get popular rooms (with most users).
     */
    public function scopePopular($query, int $limit = 10)
    {
        return $query->withCount('users')
            ->orderBy('users_count', 'desc')
            ->limit($limit);
    }
}
