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
        Schema::create('chat_moderation_actions', function (Blueprint $table) {
            $table->id()->comment('操作 ID');
            $table->unsignedBigInteger('room_id')->index()->comment('所属房间 ID');
            $table->unsignedBigInteger('moderator_id')->nullable()->index()->comment('执行管理的用户 ID（自动处理时为 null）');
            $table->unsignedBigInteger('target_user_id')->nullable()->index()->comment('被操作用户 ID');
            $table->unsignedBigInteger('message_id')->nullable()->index()->comment('被操作的消息 ID');
            $table->enum('action_type', [
                'delete_message',
                'mute_user',
                'unmute_user',
                'timeout_user',
                'ban_user',
                'unban_user',
                'content_filter',
                'spam_detection',
                'report_message',
            ])->comment('操作类型');
            $table->text('reason')->nullable()->comment('操作原因');
            $table->json('metadata')->nullable()->comment('附加数据（如禁言时长等 JSON）');
            $table->timestamps();

            $table->index(['room_id', 'created_at']);
            $table->index(['moderator_id', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_moderation_actions COMMENT = '房间管理操作日志表'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_moderation_actions');
    }
};
