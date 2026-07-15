<?php

namespace App\Http\Middleware;

use App\Http\Resources\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateChatSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenUser = $request->user('sanctum');
        if ($tokenUser instanceof User) {
            $request->setUserResolver(static fn (): User => $tokenUser);
            Auth::setUser($tokenUser);

            return $next($request);
        }

        $identity = $request->session()->get('chat_identity');

        if (! is_array($identity) || ! isset($identity['id'])) {
            return ApiResponse::unauthorized('请先通过 DogeOW 统一登录');
        }

        $user = User::query()->find((int) $identity['id']);
        if (! $user) {
            $request->session()->forget(['chat_identity', 'chat_identity_refreshed_at']);

            return ApiResponse::unauthorized('聊天身份已失效，请重新登录');
        }

        $request->setUserResolver(static fn (): User => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
