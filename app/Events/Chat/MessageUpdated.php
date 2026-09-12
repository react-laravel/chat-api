<?php

namespace App\Events\Chat;

class MessageUpdated extends MessageSent
{
    public function broadcastAs(): string
    {
        return 'message.updated';
    }
}
