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
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id()->comment('房间 ID');
            $table->string('name')->comment('房间名称');
            $table->text('description')->nullable()->comment('房间描述');
            $table->unsignedBigInteger('created_by')->comment('创建者用户 ID');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->boolean('is_private')->default(false)->comment('是否私有房间');
            $table->timestamps();

            $table->index(['is_active', 'created_at']);
            $table->index(['is_active', 'is_private']);
            $table->index('created_by');
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_rooms COMMENT = '聊天房间表'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_rooms');
    }
};
