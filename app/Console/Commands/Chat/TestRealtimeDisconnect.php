<?php

namespace App\Console\Commands\Chat;

use App\Services\Chat\WebSocketDisconnectService;
use Illuminate\Console\Command;

class TestRealtimeDisconnect extends Command
{
    /**
     * 命令名称及签名
     *
     * @var string
     */
    protected $signature = 'chat:test-realtime-disconnect {user_id} {--room_id=1}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '测试指定用户的实时 WebSocket 断开连接检测';

    protected $disconnectService;

    public function __construct(WebSocketDisconnectService $disconnectService)
    {
        parent::__construct();
        $this->disconnectService = $disconnectService;
    }

    /**
     * 执行控制台命令
     */
    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        $roomId = (int) $this->option('room_id');

        $this->info("正在测试用户 {$userId} 在房间 {$roomId} 中的实时断开连接检测");

        // 检查用户是否在房间中
        $isOnline = $this->disconnectService->isUserOnlineInRoom($userId, $roomId);
        $this->info("用户 {$userId} 当前在房间 {$roomId} 中".($isOnline ? '在线' : '离线'));

        if (! $isOnline) {
            $this->warn('用户不在房间中在线。无法测试断开连接。');

            return Command::SUCCESS;
        }

        // 获取当前在线人数
        $onlineCount = $this->disconnectService->getRoomOnlineCount($roomId);
        $this->info("房间 {$roomId} 当前在线人数: {$onlineCount}");

        // 模拟断开连接
        $this->info("正在模拟用户 {$userId} 的断开连接 ...");
        $this->disconnectService->handleDisconnect($userId);

        // 检查断开后的状态
        $newOnlineCount = $this->disconnectService->getRoomOnlineCount($roomId);
        $this->info("断开连接后在线人数: {$newOnlineCount}");

        $isStillOnline = $this->disconnectService->isUserOnlineInRoom($userId, $roomId);
        $this->info("用户 {$userId} 现在在房间 {$roomId} 中".($isStillOnline ? '在线' : '离线'));

        if (! $isStillOnline && $newOnlineCount === $onlineCount - 1) {
            $this->info('✅ 实时断开连接检测工作正常！');
        } else {
            $this->error('❌ 实时断开连接检测失败！');
        }

        return Command::SUCCESS;
    }
}
