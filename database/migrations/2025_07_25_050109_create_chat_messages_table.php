<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id()->comment('消息 ID');
            $table->unsignedBigInteger('room_id')->index()->comment('所属房间 ID');
            $table->unsignedBigInteger('user_id')->index()->comment('发送者用户 ID');
            $table->text('message')->comment('消息内容');
            $table->enum('message_type', ['text', 'system'])->default('text')->comment('消息类型：text 文本/system 系统消息');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['room_id', 'id', 'created_at'], 'idx_room_id_cursor');
            if (config('database.default') !== 'sqlite') {
                $table->fullText('message', 'idx_message_fulltext');
            }
            $table->index(['user_id', 'created_at'], 'idx_user_messages');
            $table->index(['room_id', 'message_type', 'created_at'], 'idx_room_type_time');
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_messages COMMENT = '聊天消息表'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
