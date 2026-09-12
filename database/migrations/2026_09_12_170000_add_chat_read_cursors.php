<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_room_users', function (Blueprint $table) {
            $table->foreignId('last_read_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
        });
        // Existing history predates read tracking; do not turn it all into unread notifications.
        DB::table('chat_room_users')->update(['last_read_message_id' => DB::raw('(SELECT MAX(id) FROM chat_messages WHERE chat_messages.room_id = chat_room_users.room_id AND chat_messages.deleted_at IS NULL)')]);
    }

    public function down(): void
    {
        Schema::table('chat_room_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_read_message_id');
            $table->dropColumn('last_read_at');
        });
    }
};
