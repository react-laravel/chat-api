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
        Schema::create('chat_message_reports', function (Blueprint $table) {
            $table->id()->comment('举报 ID');
            $table->unsignedBigInteger('message_id')->nullable()->index()->comment('被举报的消息 ID');
            $table->unsignedBigInteger('reported_by')->index()->comment('举报者用户 ID');
            $table->unsignedBigInteger('room_id')->index()->comment('所属房间 ID');
            $table->enum('report_type', [
                'inappropriate_content',
                'spam',
                'harassment',
                'hate_speech',
                'violence',
                'sexual_content',
                'misinformation',
                'other',
            ])->comment('举报类型');
            $table->text('reason')->nullable()->comment('举报说明');
            $table->enum('status', ['pending', 'reviewed', 'resolved', 'dismissed'])->default('pending')->comment('审核状态：pending 待处理/reviewed 已审核/resolved 已处理/dismissed 已驳回');
            $table->unsignedBigInteger('reviewed_by')->nullable()->index()->comment('审核者用户 ID');
            $table->timestamp('reviewed_at')->nullable()->comment('审核时间');
            $table->text('review_notes')->nullable()->comment('审核备注');
            $table->json('metadata')->nullable()->comment('附加上下文数据（JSON）');
            $table->timestamps();

            $table->index(['message_id', 'reported_by']);
            $table->index(['status', 'created_at']);
            $table->index(['room_id', 'status']);
            $table->index(['reported_by', 'created_at']);
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_message_reports COMMENT = '消息举报表'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_message_reports');
    }
};
