<?php

namespace App\Listeners;

use App\Events\Chat\WebSocketDisconnected;
use App\Services\Chat\WebSocketDisconnectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class WebSocketDisconnectListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * WebSocketDisconnectService 实例
     */
    protected WebSocketDisconnectService $disconnectService;

    public function __construct(WebSocketDisconnectService $disconnectService)
    {
        $this->disconnectService = $disconnectService;
    }

    /**
     * 处理 WebSocket 断开事件
     */
    public function handle(WebSocketDisconnected $event): void
    {
        $this->disconnectService->handleDisconnect($event->user->id, $event->connectionId);
    }
}
