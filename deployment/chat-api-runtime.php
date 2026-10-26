<?php

declare(strict_types=1);

function chatRuntimePaths(): array
{
    return [
        'LARAVEL_STORAGE_PATH' => '/var/www/chat-api/shared/storage',
        'APP_CONFIG_CACHE' => '/var/lib/dogeow-tests-chat-api/cache/config.php',
        'APP_ROUTES_CACHE' => '/var/lib/dogeow-tests-chat-api/cache/routes.php',
        'APP_EVENTS_CACHE' => '/var/lib/dogeow-tests-chat-api/cache/events.php',
        'APP_PACKAGES_CACHE' => '/var/lib/dogeow-tests-chat-api/cache/packages.php',
        'APP_SERVICES_CACHE' => '/var/lib/dogeow-tests-chat-api/cache/services.php',
    ];
}

function chatApplication(): Illuminate\Foundation\Application
{
    $user = posix_getpwnam('dogeow-test-chat-api');
    if (!$user || posix_geteuid() !== $user['uid']) {
        throw new RuntimeException('Run only as the dedicated API runtime user.');
    }
    foreach (chatRuntimePaths() as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $root = realpath('/var/www/chat-api/current');
    if (!$root || !str_starts_with($root, '/var/www/chat-api/releases/')) {
        throw new RuntimeException('The current API release is unavailable.');
    }
    if (!defined('LARAVEL_START')) {
        define('LARAVEL_START', microtime(true));
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath('/var/www/chat-api/shared');
    $app->loadEnvironmentFrom('.env');

    return $app;
}
