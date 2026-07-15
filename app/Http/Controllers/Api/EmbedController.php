<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EmbedController extends Controller
{
    public function show(Request $request, ChatIntegrationService $integrations): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'origin' => ['required', 'url:http,https', 'max:2048'],
        ]);

        try {
            $integration = $integrations->resolve($validated['client'], $validated['origin']);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), null, 403);
        }

        return $this->success([
            'client_id' => $integration->client_id,
            'name' => $integration->name,
            'default_room_id' => $integration->default_room_id,
        ]);
    }
}
