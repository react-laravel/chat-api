<?php

namespace App\Console\Commands\Chat;

use App\Services\Chat\ChatCacheService;
use Illuminate\Console\Command;

/**
 * 此命令用于**清除所有聊天室相关的缓存数据**。
 * 适用于遇到聊天室缓存异常或需要强制刷新缓存时，通过 Artisan 执行：php artisan chat:clear-cache
 */
class ClearChatCache extends Command
{
    /**
     * 控制台命令名称及参数
     *
     * @var string
     */
    protected $signature = 'chat:clear-cache';

    /**
     * 控制台命令描述
     *
     * @var string
     */
    protected $description = '清除所有聊天室相关的缓存';

    protected ChatCacheService $cacheService;

    public function __construct(ChatCacheService $cacheService)
    {
        parent::__construct();
        $this->cacheService = $cacheService;
    }

    /**
     * 执行控制台命令
     */
    public function handle(): int
    {
        $this->info('正在清理聊天室缓存 ...');

        try {
            $this->cacheService->clearAllCache();
            $this->info('聊天室缓存清理成功！');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('清理缓存失败：'.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
