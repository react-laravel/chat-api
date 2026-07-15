<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CleanupDisconnectedChatUsers extends Command
{
    /**
     * 命令名称及参数配置
     *
     * @var string
     *
     * 调用示例:
     * php artisan chat:cleanup-disconnected --minutes=10
     *
     * 参数说明:
     * --minutes  指定聊天室用户的非活跃时间阈值(分钟)，达到该时长未活跃的用户会被标记为离线。默认为 5 分钟。
     */
    protected $signature = 'chat:cleanup-disconnected {--minutes=5 : 用户多久未活跃(分钟)后被标记为离线，默认 5 分钟}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '清理指定分钟未活跃的聊天室用户(将其状态标记为离线)';

    /**
     * 执行控制台命令
     */
    public function handle(): int
    {
        $inactiveMinutes = (int) $this->option('minutes');

        $this->info("开始清理未活跃超过 {$inactiveMinutes} 分钟的聊天室用户 ...");

        try {
            DB::beginTransaction();

            // 查询指定分钟内未活跃的在线用户
            /** @var Collection<int, ChatRoomUser> $inactiveUsers */
            $inactiveUsers = ChatRoomUser::online()
                ->inactiveSince($inactiveMinutes)
                ->with(['user:id,name', 'room:id,name'])
                ->get();

            $cleanedCount = 0;
            /** @var ChatRoomUser $roomUser */
            foreach ($inactiveUsers as $roomUser) {
                /** @var User|null $user */
                $user = $roomUser->user;
                /** @var ChatRoom|null $room */
                $room = $roomUser->room;
                $this->line('将用户 '.$user?->name." 标记为在房间 '".$room?->name."' 离线");
                $roomUser->markAsOffline();
                $cleanedCount++;
            }

            DB::commit();

            $this->info("清理完成，共有 {$cleanedCount} 名用户被标记为离线。");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('清理异常：'.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
