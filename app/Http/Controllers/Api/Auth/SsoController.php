<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\IdentitySsoClient;
use App\Services\Chat\ChatIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SsoController extends Controller
{
    public function exchange(
        Request $request,
        IdentitySsoClient $client,
        ChatIntegrationService $integrations,
    ): JsonResponse {
        $validated = $request->validate([
            'ticket' => ['required', 'string', 'min:32', 'max:512'],
            'client_id' => ['nullable', 'required_with:parent_origin', 'string', 'max:64'],
            'parent_origin' => ['nullable', 'required_with:client_id', 'url:http,https', 'max:2048'],
        ]);

        $integration = null;
        if (isset($validated['client_id'], $validated['parent_origin'])) {
            try {
                $integration = $integrations->resolve($validated['client_id'], $validated['parent_origin']);
            } catch (InvalidArgumentException $exception) {
                return $this->error($exception->getMessage(), null, 403);
            }
        }

        try {
            $identity = $client->exchange($validated['ticket']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 401);
        }

        $user = User::query()->updateOrCreate(
            ['id' => (int) $identity['id']],
            [
                'name' => (string) $identity['name'],
                'email' => $identity['email'] ?? null,
                'is_admin' => (bool) ($identity['is_admin'] ?? false),
                'permissions' => array_values(array_unique(array_map('strval', $identity['permissions'] ?? []))),
                'last_synced_at' => now(),
            ],
        );

        $request->session()->regenerate();
        $request->session()->put('chat_identity', $user->only(['id', 'name', 'email', 'is_admin', 'permissions']));
        $request->session()->put('chat_identity_refreshed_at', now()->timestamp);
        $request->session()->put('chat_integration', $integration ? [
            'client_id' => $integration->client_id,
            'parent_origin' => $validated['parent_origin'],
        ] : null);

        return $this->success($user->toArray(), '登录成功');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->success($request->user()->toArray());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, '已退出 Chat');
    }
}
