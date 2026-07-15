<?php

namespace App\Console\Commands\Chat;

use App\Events\Chat\WebSocketDisconnected;
use App\Models\User;
use App\Services\Chat\WebSocketDisconnectService;
use Illuminate\Console\Command;

class TestWebSocketRealtime extends Command
{
    /**
     * 命令名称及签名
     *
     * @var string
     */
    protected $signature = 'chat:test-realtime {user_id} {--room_id=1} {--simulate-disconnect}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '测试实时 WebSocket 断开连接检测和清理';

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
        $simulateDisconnect = $this->option('simulate-disconnect');

        $this->info('🧪 正在测试实时 WebSocket 断开连接检测');
        $this->info("用户 ID: {$userId}");
        $this->info("房间 ID: {$roomId}");
        $this->newLine();

        // 检查用户是否存在
        $user = User::find($userId);
        if (! $user) {
            $this->error("❌ 未找到 ID 为 {$userId} 的用户");

            return Command::FAILURE;
        }

        $this->info("✅ 找到用户: {$user->name}");

        // 检查用户当前状态
        $isOnline = $this->disconnectService->isUserOnlineInRoom($userId, $roomId);
        $onlineCount = $this->disconnectService->getRoomOnlineCount($roomId);

        $this->info('当前状态:');
        $this->info("- 用户在房间 {$roomId} 中".($isOnline ? '在线' : '离线'));
        $this->info("- 房间 {$roomId} 有 {$onlineCount} 个在线用户");

        if ($simulateDisconnect) {
            $this->newLine();
            $this->info('🔄 正在模拟 WebSocket 断开连接 ...');

            // 模拟断开连接事件
            event(new WebSocketDisconnected($user, 'test-connection-id'));

            // 等待一下让事件处理完成
            sleep(1);

            // 检查断开后的状态
            $newIsOnline = $this->disconnectService->isUserOnlineInRoom($userId, $roomId);
            $newOnlineCount = $this->disconnectService->getRoomOnlineCount($roomId);

            $this->info('断开连接后:');
            $this->info("- 用户在房间 {$roomId} 中".($newIsOnline ? '在线' : '离线'));
            $this->info("- 房间 {$roomId} 有 {$newOnlineCount} 个在线用户");

            if (! $newIsOnline && $newOnlineCount === $onlineCount - 1) {
                $this->info('✅ 实时断开连接检测工作正常！');
            } else {
                $this->error('❌ 实时断开连接检测失败！');

                return Command::FAILURE;
            }
        } else {
            $this->newLine();
            $this->info('💡 使用 --simulate-disconnect 标志来测试断开连接功能');
        }

        return Command::SUCCESS;
    }
}
