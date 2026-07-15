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
        Schema::create('chat_room_users', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->unsignedBigInteger('room_id')->index()->comment('房间 ID');
            $table->unsignedBigInteger('user_id')->index()->comment('用户 ID');
            $table->timestamp('joined_at')->nullable()->comment('加入时间');
            $table->timestamp('last_seen_at')->nullable()->comment('最后在线时间');
            $table->boolean('is_online')->default(false)->comment('当前是否在线');
            $table->boolean('is_muted')->default(false)->comment('是否被禁言');
            $table->timestamp('muted_until')->nullable()->comment('禁言到期时间');
            $table->boolean('is_banned')->default(false)->comment('是否被封禁');
            $table->timestamp('banned_until')->nullable()->comment('封禁到期时间');
            $table->unsignedBigInteger('muted_by')->nullable()->index()->comment('执行禁言的管理员 ID');
            $table->unsignedBigInteger('banned_by')->nullable()->index()->comment('执行封禁的管理员 ID');
            $table->timestamps();

            $table->unique(['room_id', 'user_id'], 'unique_room_user');
            $table->index(['room_id', 'is_online']);
            $table->index(['user_id', 'is_online']);
            $table->index('last_seen_at');
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_room_users COMMENT = '房间成员表（管理禁言/封禁状态）'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_room_users');
    }
};
