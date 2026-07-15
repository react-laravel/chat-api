<?php

namespace App\Console\Commands\Chat;

use App\Services\Chat\ChatCacheService;
use Illuminate\Console\Command;

class WarmChatCache extends Command
{
    /**
     * 控制台命令名称及签名。
     *
     * @var string
     */
    protected $signature = 'chat:warm-cache';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '预热聊天相关缓存以提升性能';

    protected ChatCacheService $cacheService;

    public function __construct(ChatCacheService $cacheService)
    {
        parent::__construct();
        $this->cacheService = $cacheService;
    }

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $this->info('正在预热聊天缓存 ...');

        try {
            $this->cacheService->warmUpCache();
            $this->info('聊天缓存预热成功！');

            // 展示缓存统计信息
            $stats = $this->cacheService->getCacheStats();
            $this->table(['指标', '值'], [
                ['缓存驱动', $stats['driver']],
                ['内存使用量', $stats['memory_usage'] ?? '无'],
                ['连接客户端数', $stats['connected_clients'] ?? '无'],
                ['键命中次数', $stats['keyspace_hits'] ?? '无'],
                ['键未命中次数', $stats['keyspace_misses'] ?? '无'],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('预热缓存失败：'.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
