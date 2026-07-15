<?php

namespace Tests\Feature;

use App\Models\ChatIntegration;
use Tests\TestCase;

class EmbedConfigTest extends TestCase
{
    public function test_it_returns_configuration_for_an_exact_allowed_origin(): void
    {
        ChatIntegration::query()->where('client_id', 'rpg')->update([
            'default_room_id' => 42,
        ]);

        $response = $this->getJson('/api/embed/config?client=rpg&origin='.urlencode('https://rpg.dogeow.com'));

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'client_id' => 'rpg',
                'name' => 'DogeOW RPG',
                'default_room_id' => 42,
            ],
        ]);
    }

    public function test_it_normalizes_paths_but_keeps_the_origin_exact(): void
    {
        $response = $this->getJson('/api/embed/config?client=game&origin='.urlencode('https://game.dogeow.com/game/snake?embed=1'));

        $response->assertOk()->assertJsonPath('data.client_id', 'game');
    }

    public function test_it_rejects_an_origin_that_is_not_registered_for_the_client(): void
    {
        $response = $this->getJson('/api/embed/config?client=rpg&origin='.urlencode('https://evil.example'));

        $response->assertForbidden()->assertJson([
            'success' => false,
            'message' => '当前网站未获准嵌入 Chat。',
        ]);
    }

    public function test_it_rejects_a_disabled_integration(): void
    {
        ChatIntegration::query()->where('client_id', 'repo-watch')->update(['enabled' => false]);

        $response = $this->getJson('/api/embed/config?client=repo-watch&origin='.urlencode('https://repo-watch.dogeow.com'));

        $response->assertForbidden()->assertJsonPath('message', '未知或已停用的 Chat 接入方。');
    }
}
