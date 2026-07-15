<?php

namespace App\Services\Chat;

use App\Models\ChatIntegration;
use InvalidArgumentException;

class ChatIntegrationService
{
    public function resolve(string $clientId, string $origin): ChatIntegration
    {
        $normalizedOrigin = $this->normalizeOrigin($origin);
        $integration = ChatIntegration::query()
            ->where('client_id', $clientId)
            ->where('enabled', true)
            ->first();

        if (! $integration) {
            throw new InvalidArgumentException('未知或已停用的 Chat 接入方。');
        }

        $allowed = collect($integration->allowed_origins ?? [])
            ->map(fn (string $value): string => $this->normalizeOrigin($value))
            ->contains($normalizedOrigin);

        if (! $allowed) {
            throw new InvalidArgumentException('当前网站未获准嵌入 Chat。');
        }

        return $integration;
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('无效的嵌入来源。');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('无效的嵌入来源。');
        }

        $normalized = $scheme.'://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':'.(int) $parts['port'];
        }

        return $normalized;
    }
}
