<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatRoomInvite extends Model
{
    protected $fillable = ['room_id', 'created_by', 'token_hash', 'expires_at', 'revoked_at', 'max_uses', 'uses_count'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'max_uses' => 'integer', 'uses_count' => 'integer'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }
}
