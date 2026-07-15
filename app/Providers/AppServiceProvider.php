<?php

namespace App\Providers;

use App\Events\Chat\WebSocketDisconnected;
use App\Listeners\WebSocketDisconnectListener;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('chat-message', static function (Request $request): Limit {
            $user = $request->user('sanctum');

            return Limit::perMinute(10)->by((string) ($user?->getAuthIdentifier() ?? $request->ip()));
        });

        Event::listen(WebSocketDisconnected::class, WebSocketDisconnectListener::class);
        require base_path('routes/channels.php');
    }
}
