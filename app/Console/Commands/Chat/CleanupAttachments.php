<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\ChatAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAttachments extends Command
{
    protected $signature = 'chat:cleanup-attachments {--hours=24 : Minimum age of unsent uploads}';

    protected $description = 'Remove expired unsent chat attachments from private storage';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $removed = 0;
        ChatAttachment::whereNull('message_id')->where('created_at', '<', now()->subHours($hours))
            ->chunkById(100, function ($attachments) use (&$removed) {
                foreach ($attachments as $attachment) {
                    if (! Storage::disk('local')->exists($attachment->path) || Storage::disk('local')->delete($attachment->path)) {
                        $attachment->delete();
                        $removed++;
                    }
                }
            });
        $this->info('Removed '.$removed.' expired attachment(s).');

        return self::SUCCESS;
    }
}
