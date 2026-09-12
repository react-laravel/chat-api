<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;

class ChatRealtime
{
    /** Persistence is authoritative; a broker outage must not make a saved message look rejected. */
    public static function publish(object $event): bool
    {
        try {
            event($event);

            return true;
        } catch (\Throwable $error) {
            Log::warning('Chat realtime publish failed; saved messages remain available in history', [
                'event' => $event::class, 'error' => $error->getMessage(),
            ]);

            return false;
        }
    }
}
