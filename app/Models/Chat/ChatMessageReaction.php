<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

class ChatMessageReaction extends Model
{
    public const EMOJIS = ['👍', '❤️', '😂', '🎉', '👀', '🙏'];

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    protected $casts = ['message_id' => 'integer', 'user_id' => 'integer'];
}
