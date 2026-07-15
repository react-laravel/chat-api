<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class WebSocketAuthMiddleware
{
    /**
     * 处理 WebSocket 认证请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 优先从 query 参数获取 token，其次获取 Bearer Token
        $token = $request->query('token');
        if (! $token) {
            $token = $request->bearerToken();
        }

        if (! $token) {
            return response()->json(['error' => '未授权：缺少 token'], 401);
        }

        // 通过 Sanctum 查找并验证 AccessToken
        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable) {
            return response()->json(['error' => '未授权：Token 无效'], 401);
        }

        // 检查 Token 是否过期
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json(['error' => '未授权：Token 已过期'], 401);
        }

        // 设置已认证用户
        $user = $accessToken->tokenable;
        assert($user instanceof Authenticatable);
        Auth::setUser($user);

        // 更新 Token 最后活动时间
        $accessToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $next($request);
    }
}
