<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebSocketAuthTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_ENDPOINT = '/api/broadcasting/auth';

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'reverb']);

        require base_path('routes/channels.php');
    }

    public function test_private_channel_requires_token(): void
    {
        $response = $this->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-chat.room.1',
        ]);

        $response->assertUnauthorized();
    }

    public function test_private_channel_accepts_valid_token(): void
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_online' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => "private-chat.room.{$room->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }

    public function test_private_channel_rejects_invalid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-chat.room.1',
        ]);

        $response->assertUnauthorized();
    }

    public function test_public_channel_auth_request_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'log-updates',
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }

    public function test_private_channel_with_malformed_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer',
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-chat.room.1',
        ]);

        $response->assertUnauthorized();
    }

    public function test_private_channel_without_authorization_header(): void
    {
        $response = $this->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-chat.room.1',
        ]);

        $response->assertUnauthorized();
    }

    public function test_private_channel_with_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['*'], now()->subDay())->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-chat.room.1',
        ]);

        $response->assertUnauthorized();
    }

    public function test_presence_channel_requires_auth(): void
    {
        $response = $this->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'presence-chat.room.1.presence',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_private_channel_requires_matching_user_id(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 尝试订阅其他用户的私有频道
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-user.'.$otherUser->id.'.notifications',
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }

    public function test_user_private_channel_allows_own_user_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // 订阅自己的私有频道
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson(self::AUTH_ENDPOINT, [
            'channel_name' => 'private-user.'.$user->id.'.notifications',
            'socket_id' => '123.456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }
}
