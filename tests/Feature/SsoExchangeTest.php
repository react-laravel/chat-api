<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\IdentitySsoClient;
use Mockery\MockInterface;
use Tests\TestCase;

class SsoExchangeTest extends TestCase
{
    public function test_it_exchanges_a_ticket_and_persists_the_local_identity_snapshot(): void
    {
        $ticket = str_repeat('a', 40);
        $this->mock(IdentitySsoClient::class, function (MockInterface $mock) use ($ticket): void {
            $mock->shouldReceive('exchange')->once()->with($ticket)->andReturn([
                'id' => 123,
                'name' => 'Chat User',
                'email' => 'chat@example.com',
                'is_admin' => false,
                'permissions' => ['chat.moderate'],
            ]);
        });

        $response = $this->postJson('/api/auth/exchange', [
            'ticket' => $ticket,
            'client_id' => 'rpg',
            'parent_origin' => 'https://rpg.dogeow.com/game/rpg',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', 123)
            ->assertSessionHas('chat_identity.id', 123)
            ->assertSessionHas('chat_integration.client_id', 'rpg')
            ->assertSessionHas('chat_integration.parent_origin', 'https://rpg.dogeow.com/game/rpg');

        $this->assertDatabaseHas('chat_users', [
            'id' => 123,
            'name' => 'Chat User',
            'email' => 'chat@example.com',
        ]);
    }

    public function test_it_rejects_an_unregistered_parent_before_consuming_the_ticket(): void
    {
        $this->mock(IdentitySsoClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('exchange');
        });

        $response = $this->postJson('/api/auth/exchange', [
            'ticket' => str_repeat('b', 40),
            'client_id' => 'rpg',
            'parent_origin' => 'https://evil.example',
        ]);

        $response->assertForbidden()->assertJsonPath('message', '当前网站未获准嵌入 Chat。');
        $this->assertDatabaseCount((new User)->getTable(), 0);
    }
}
