<?php

namespace App\Models;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 中央 DogeOW 账号在 Chat 数据库中的只读身份快照。
 *
 * 主键与中央用户 ID 保持一致。Chat API 只有自己的数据库连接，
 * 姓名、邮箱和权限在每次 SSO 登录时刷新。
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $table = 'chat_users';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'name',
        'email',
        'is_admin',
        'permissions',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'is_admin' => 'boolean',
            'permissions' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function canModerate(?ChatRoom $room = null): bool
    {
        return $this->isAdmin() || ($room !== null && (int) $room->created_by === (int) $this->id);
    }

    public function hasRole(string $role): bool
    {
        return match ($role) {
            'admin' => $this->isAdmin(),
            'moderator' => in_array('chat.moderate', $this->permissions ?? [], true),
            default => false,
        };
    }

    public function createdRooms()
    {
        return $this->hasMany(ChatRoom::class, 'created_by');
    }

    public function joinedRooms()
    {
        return $this->belongsToMany(ChatRoom::class, 'chat_room_users', 'user_id', 'room_id')
            ->withPivot(['joined_at', 'last_seen_at', 'is_online'])
            ->withTimestamps();
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: (string) $this->email;
    }

    public function getInitialsAttribute(): string
    {
        $name = $this->display_name;
        $words = preg_split('/\s+/', trim($name)) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    public function isOnlineInAnyRoom(): bool
    {
        return $this->joinedRooms()->wherePivot('is_online', true)->exists();
    }
}
