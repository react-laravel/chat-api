<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\Api\Chat\ChatModerationController;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class ChatModerationControllerUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_active_room_returns_active_room(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => true]);

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('findActiveRoom');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $room->id);

        $this->assertSame($room->id, $result->id);
    }

    public function test_find_active_room_throws_when_room_inactive(): void
    {
        $room = ChatRoom::factory()->create(['is_active' => false]);

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('findActiveRoom');
        $method->setAccessible(true);

        $this->expectException(ModelNotFoundException::class);

        $method->invoke($controller, $room->id);
    }

    public function test_find_room_user_returns_room_membership(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();
        ChatRoomUser::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('findRoomUser');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $room->id, $user->id);

        $this->assertNotNull($result);
        $this->assertSame($room->id, $result->room_id);
        $this->assertSame($user->id, $result->user_id);
    }

    public function test_find_room_user_returns_null_when_membership_missing(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('findRoomUser');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $room->id, $user->id);

        $this->assertNull($result);
    }

    public function test_get_moderator_returns_authenticated_user(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('getAttribute')->with('id')->andReturn(789);
        $user->shouldReceive('getAttribute')->with('name')->andReturn('Moderator');

        // For Auth::user() mock
        Auth::shouldReceive('user')->andReturn($user);

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('getModerator');
        $method->setAccessible(true);

        $returnedUser = $method->invoke($controller);
        $this->assertSame($user, $returnedUser);
    }

    public function test_ensure_can_moderate_returns_null_when_user_can_moderate(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('canModerate')->andReturn(true);

        $room = Mockery::mock('App\Models\Chat\ChatRoom');

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('ensureCanModerate');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, $room, 'Access denied');
        $this->assertNull($result);
    }

    public function test_ensure_can_moderate_returns_403_when_user_cannot_moderate(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('canModerate')->andReturn(false);

        $room = Mockery::mock('App\Models\Chat\ChatRoom');

        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('ensureCanModerate');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, $room, 'Access denied');
        $this->assertNotNull($result);
        $this->assertEquals(403, $result->getStatusCode());
        $this->assertStringContainsString('Access denied', $result->getContent());
    }

    public function test_ensure_not_self_moderation_returns_null_when_different_users(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('ensureNotSelfModeration');
        $method->setAccessible(true);

        $result = $method->invoke($controller, 100, 200, 'Cannot self-moderate');
        $this->assertNull($result);
    }

    public function test_ensure_not_self_moderation_returns_422_when_same_user(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('ensureNotSelfModeration');
        $method->setAccessible(true);

        $result = $method->invoke($controller, 100, 100, 'Cannot self-moderate');
        $this->assertNotNull($result);
        $this->assertEquals(422, $result->getStatusCode());
        $this->assertStringContainsString('Cannot self-moderate', $result->getContent());
    }

    public function test_log_and_error_returns_500_json_response(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('logAndError');
        $method->setAccessible(true);

        $exception = new \Exception('Test error message');
        $result = $method->invoke(
            $controller,
            'Failed operation',
            $exception,
            ['key' => 'value'],
            'User-friendly message',
            500
        );

        $this->assertNotNull($result);
        $this->assertEquals(500, $result->getStatusCode());
        $this->assertStringContainsString('User-friendly message', $result->getContent());
    }

    public function test_log_and_error_returns_custom_status_code(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('logAndError');
        $method->setAccessible(true);

        $exception = new \Exception('Test error');
        $result = $method->invoke(
            $controller,
            'Failed operation',
            $exception,
            [],
            'Error occurred',
            503
        );

        $this->assertEquals(503, $result->getStatusCode());
    }

    public function test_parse_moderation_filters_extracts_parameters(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('parseModerationFilters');
        $method->setAccessible(true);

        $request = Mockery::mock('Illuminate\Http\Request');
        $request->shouldReceive('get')->with('per_page', 20)->andReturn(10);
        $request->shouldReceive('get')->with('action_type')->andReturn('mute');
        $request->shouldReceive('get')->with('target_user_id')->andReturn(456);

        $filters = $method->invoke($controller, $request);

        $this->assertEquals(10, $filters['per_page']);
        $this->assertEquals('mute', $filters['action_type']);
        $this->assertEquals(456, $filters['target_user_id']);
    }

    public function test_parse_moderation_filters_uses_default_per_page(): void
    {
        $controller = new ChatModerationController;

        $reflection = new ReflectionClass(ChatModerationController::class);
        $method = $reflection->getMethod('parseModerationFilters');
        $method->setAccessible(true);

        $request = Mockery::mock('Illuminate\Http\Request');
        $request->shouldReceive('get')->with('per_page', 20)->andReturn(20);
        $request->shouldReceive('get')->with('action_type')->andReturn(null);
        $request->shouldReceive('get')->with('target_user_id')->andReturn(null);

        $filters = $method->invoke($controller, $request);

        $this->assertEquals(20, $filters['per_page']);
        $this->assertNull($filters['action_type']);
        $this->assertNull($filters['target_user_id']);
    }
}
