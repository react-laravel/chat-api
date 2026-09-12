<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAttachment extends Model
{
    public const MAX_FILE_KB = 10240;

    public const MAX_FILES_PER_MESSAGE = 5;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    protected $fillable = ['room_id', 'user_id', 'message_id', 'client_id', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['path'];

    protected $casts = ['room_id' => 'integer', 'user_id' => 'integer', 'message_id' => 'integer', 'size' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function payload(): array
    {
        return ['id' => $this->id, 'name' => $this->original_name, 'mime_type' => $this->mime_type,
            'size' => $this->size, 'url' => '/api/chat/rooms/'.$this->room_id.'/attachments/'.$this->id];
    }
}
