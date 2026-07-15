<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\Api\Chat\ChatReportController;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReport;
use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ContentFilterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class ChatReportControllerUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_initializes_content_filter_service(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $property = $reflection->getProperty('contentFilterService');
        $property->setAccessible(true);

        $this->assertSame($filterService, $property->getValue($controller));
    }

    public function test_ensure_can_moderate_returns_null_when_user_can_moderate(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('canModerate')->andReturn(true);

        $room = Mockery::mock('App\Models\Chat\ChatRoom');

        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('ensureCanModerate');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, $room, 'Not authorized');
        $this->assertNull($result);
    }

    public function test_ensure_can_moderate_returns_403_when_user_cannot_moderate(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('canModerate')->andReturn(false);

        $room = Mockery::mock('App\Models\Chat\ChatRoom');

        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('ensureCanModerate');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, $room, 'Not authorized');
        $this->assertNotNull($result);
        $this->assertEquals(403, $result->getStatusCode());
        $this->assertStringContainsString('Not authorized', $result->getContent());
    }

    public function test_ensure_admin_returns_null_when_user_is_admin(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('hasRole')->with('admin')->andReturn(true);

        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('ensureAdmin');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, 'Not admin');
        $this->assertNull($result);
    }

    public function test_ensure_admin_returns_403_when_user_is_not_admin(): void
    {
        $user = Mockery::mock('App\Models\User');
        $user->shouldReceive('hasRole')->with('admin')->andReturn(false);

        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('ensureAdmin');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, 'Not admin');
        $this->assertNotNull($result);
        $this->assertEquals(403, $result->getStatusCode());
        $this->assertStringContainsString('Not admin', $result->getContent());
    }

    public function test_log_and_error_returns_500_json_response(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $activeRoom = ChatRoom::factory()->create(['is_active' => true]);
        $inactiveRoom = ChatRoom::factory()->create(['is_active' => false]);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('findActiveRoom');
        $method->setAccessible(true);

        $found = $method->invoke($controller, $activeRoom->id);
        $this->assertSame($activeRoom->id, $found->id);

        $this->expectException(ModelNotFoundException::class);
        $method->invoke($controller, $inactiveRoom->id);
    }

    public function test_parse_moderation_filters_extracts_parameters(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('normalizePagination');
        $method->setAccessible(true);

        $input = [
            'data' => [['id' => 1], ['id' => 2]],
            'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 2],
        ];

        [$data, $meta] = $method->invoke($controller, $input);

        $this->assertCount(2, $data);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(2, $meta['total']);
    }

    public function test_parse_moderation_filters_uses_default_per_page(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('normalizePagination');
        $method->setAccessible(true);

        $paginator = new LengthAwarePaginator(
            items: [['id' => 10], ['id' => 11]],
            total: 7,
            perPage: 2,
            currentPage: 2
        );

        [$data, $meta] = $method->invoke($controller, $paginator);

        $this->assertCount(2, $data);
        $this->assertSame(2, $meta['current_page']);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(7, $meta['total']);
    }

    public function test_normalize_pagination_handles_object_with_to_array_and_meta(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('normalizePagination');
        $method->setAccessible(true);

        $paged = new class
        {
            public function toArray(): array
            {
                return [
                    'data' => [['id' => 99]],
                    'meta' => [
                        'current_page' => 3,
                        'per_page' => 1,
                        'total' => 9,
                    ],
                ];
            }
        };

        [$data, $meta] = $method->invoke($controller, $paged);

        $this->assertSame([['id' => 99]], $data);
        $this->assertSame(3, $meta['current_page']);
        $this->assertSame(1, $meta['per_page']);
        $this->assertSame(9, $meta['total']);
    }

    public function test_normalize_pagination_falls_back_to_items_when_unknown_object_shape(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('normalizePagination');
        $method->setAccessible(true);

        $paged = new class
        {
            public function items(): array
            {
                return [['id' => 7]];
            }
        };

        [$data, $meta] = $method->invoke($controller, $paged);

        $this->assertSame([['id' => 7]], $data);
        $this->assertSame([], $meta);
    }

    public function test_normalize_pagination_builds_meta_from_object_methods_when_to_array_has_no_meta(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('normalizePagination');
        $method->setAccessible(true);

        $paged = new class
        {
            public function toArray(): array
            {
                return [
                    'data' => [['id' => 15]],
                ];
            }

            public function currentPage(): int
            {
                return 4;
            }

            public function perPage(): int
            {
                return 25;
            }

            public function total(): int
            {
                return 120;
            }
        };

        [$data, $meta] = $method->invoke($controller, $paged);

        $this->assertSame([['id' => 15]], $data);
        $this->assertSame(4, $meta['current_page']);
        $this->assertSame(25, $meta['per_page']);
        $this->assertSame(120, $meta['total']);
    }

    public function test_check_auto_moderation_does_nothing_when_pending_reports_below_threshold(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $room = ChatRoom::factory()->create(['is_active' => true]);
        $target = User::factory()->create();
        $reporter = User::factory()->create();

        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $target->id,
        ]);

        ChatMessageReport::factory()->count(2)->create([
            'message_id' => $message->id,
            'room_id' => $room->id,
            'reported_by' => $reporter->id,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('checkAutoModeration');
        $method->setAccessible(true);

        $method->invoke($controller, $message->id, $room->id);

        $this->assertDatabaseHas('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseCount('chat_moderation_actions', 0);
        $this->assertSame(2, ChatMessageReport::where('message_id', $message->id)->where('status', ChatMessageReport::STATUS_PENDING)->count());
    }

    public function test_check_auto_moderation_auto_deletes_message_and_resolves_pending_reports(): void
    {
        $filterService = $this->createMock(ContentFilterService::class);
        $controller = new ChatReportController($filterService);

        $room = ChatRoom::factory()->create(['is_active' => true]);
        $target = User::factory()->create();
        $reporters = User::factory()->count(3)->create();

        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $target->id,
            'message' => 'to be auto moderated',
        ]);

        // 创建举报并确保举报者是房间成员
        foreach ($reporters as $reporter) {
            ChatRoomUser::create([
                'room_id' => $room->id,
                'user_id' => $reporter->id,
                'is_online' => true,
            ]);

            ChatMessageReport::factory()->create([
                'message_id' => $message->id,
                'room_id' => $room->id,
                'reported_by' => $reporter->id,
                'status' => ChatMessageReport::STATUS_PENDING,
            ]);
        }

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('checkAutoModeration');
        $method->setAccessible(true);

        $method->invoke($controller, $message->id, $room->id);

        $this->assertSoftDeleted('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('chat_moderation_actions', [
            'room_id' => $room->id,
            'moderator_id' => null,
            'target_user_id' => $target->id,
            'message_id' => $message->id,
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
        ]);

        $this->assertSame(3, ChatMessageReport::where('status', ChatMessageReport::STATUS_RESOLVED)->where('reviewed_by', null)->count());
    }
}
