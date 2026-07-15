<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatActivityService;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPresenceService;
use App\Services\Chat\ChatRoomService;
use App\Services\Chat\ChatService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    private ChatMessageService $messageService;

    private ChatRoomService $roomService;

    private ChatPresenceService $presenceService;

    private ChatActivityService $activityService;

    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messageService = Mockery::mock(ChatMessageService::class);
        $this->roomService = Mockery::mock(ChatRoomService::class);
        $this->presenceService = Mockery::mock(ChatPresenceService::class);
        $this->activityService = Mockery::mock(ChatActivityService::class);

        $this->service = new ChatService(
            $this->messageService,
            $this->roomService,
            $this->presenceService,
            $this->activityService,
            Mockery::mock(ChatCacheService::class)
        );
    }

    #[Test]
    public function it_delegates_message_operations_to_the_message_service(): void
    {
        $history = [
            'messages' => collect([ChatMessage::factory()->make()]),
            'next_cursor' => 'next-1',
        ];
        $recentMessages = collect([ChatMessage::factory()->make(), ChatMessage::factory()->make()]);
        $paginatedMessages = new LengthAwarePaginator(
            [ChatMessage::factory()->make()],
            1,
            15,
            1
        );
        $processedMessage = ['success' => true, 'message' => ChatMessage::factory()->make()];
        $systemMessage = ChatMessage::factory()->make([
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);
        $searchResults = [
            'messages' => collect([ChatMessage::factory()->make(['message' => 'needle'])]),
            'next_cursor' => null,
        ];
        $messageStats = ['total_messages' => 5];

        $this->messageService->shouldReceive('validateMessage')
            ->once()
            ->with('hello world')
            ->andReturn(['valid' => true, 'errors' => []]);
        $this->messageService->shouldReceive('sanitizeMessage')
            ->once()
            ->with('<b>hello</b>')
            ->andReturn('hello');
        $this->messageService->shouldReceive('processMentions')
            ->once()
            ->with('hello @alice')
            ->andReturn([['user_id' => 7, 'username' => 'alice']]);
        $this->messageService->shouldReceive('formatMessage')
            ->once()
            ->with('hello @alice', [['user_id' => 7, 'username' => 'alice']])
            ->andReturn('<mention data-user-id="7">@alice</mention>');
        $this->messageService->shouldReceive('getMessageHistory')
            ->once()
            ->with(10, 'cursor-1', 25, 'after')
            ->andReturn($history);
        $this->messageService->shouldReceive('getRecentMessages')
            ->once()
            ->with(10, 15)
            ->andReturn($recentMessages);
        $this->messageService->shouldReceive('getMessageHistoryPaginated')
            ->once()
            ->with(10)
            ->andReturn($paginatedMessages);
        $this->messageService->shouldReceive('processMessage')
            ->once()
            ->with(10, 20, 'body', ChatMessage::TYPE_TEXT)
            ->andReturn($processedMessage);
        $this->messageService->shouldReceive('createSystemMessage')
            ->once()
            ->with(10, 'system notice', 1)
            ->andReturn($systemMessage);
        $this->messageService->shouldReceive('searchMessages')
            ->once()
            ->with(10, 'needle', 'cursor-2', 12)
            ->andReturn($searchResults);
        $this->messageService->shouldReceive('getMessageStats')
            ->once()
            ->with(10)
            ->andReturn($messageStats);

        $this->assertSame(['valid' => true, 'errors' => []], $this->service->validateMessage('hello world'));
        $this->assertSame('hello', $this->service->sanitizeMessage('<b>hello</b>'));
        $this->assertSame([['user_id' => 7, 'username' => 'alice']], $this->service->processMentions('hello @alice'));
        $this->assertSame(
            '<mention data-user-id="7">@alice</mention>',
            $this->service->formatMessage('hello @alice', [['user_id' => 7, 'username' => 'alice']])
        );
        $this->assertSame($history, $this->service->getMessageHistory(10, 'cursor-1', 25, 'after'));
        $this->assertSame($recentMessages, $this->service->getRecentMessages(10, 15));
        $this->assertSame($paginatedMessages, $this->service->getMessageHistoryPaginated(10));
        $this->assertSame($processedMessage, $this->service->processMessage(10, 20, 'body', ChatMessage::TYPE_TEXT));
        $this->assertSame($systemMessage, $this->service->createSystemMessage(10, 'system notice'));
        $this->assertSame($searchResults, $this->service->searchMessages(10, 'needle', 'cursor-2', 12));
        $this->assertSame($messageStats, $this->service->getMessageStats(10));
    }

    #[Test]
    public function it_delegates_room_operations_to_the_room_service(): void
    {
        $roomPayload = ['name' => 'Room A', 'description' => 'Alpha'];
        $validationResult = ['valid' => true, 'errors' => []];
        $createResult = ['success' => true, 'room' => (object) ['id' => 11]];
        $deleteResult = ['success' => true];
        $updateResult = ['success' => true, 'room' => (object) ['id' => 11, 'name' => 'Room B']];
        $stats = ['room' => (object) ['id' => 11], 'messages' => ['total_messages' => 9]];
        $activeRooms = new Collection([(object) ['id' => 11], (object) ['id' => 12]]);

        $this->roomService->shouldReceive('validateRoomData')
            ->once()
            ->with($roomPayload)
            ->andReturn($validationResult);
        $this->roomService->shouldReceive('createRoom')
            ->once()
            ->with($roomPayload, 21)
            ->andReturn($createResult);
        $this->roomService->shouldReceive('checkRoomPermission')
            ->once()
            ->with(11, 21, 'delete')
            ->andReturnTrue();
        $this->roomService->shouldReceive('deleteRoom')
            ->once()
            ->with(11, 21)
            ->andReturn($deleteResult);
        $this->roomService->shouldReceive('updateRoom')
            ->once()
            ->with(11, ['name' => 'Room B'], 21)
            ->andReturn($updateResult);
        $this->roomService->shouldReceive('getRoomStats')
            ->once()
            ->with(11)
            ->andReturn($stats);
        $this->roomService->shouldReceive('getActiveRooms')
            ->once()
            ->with(21)
            ->andReturn($activeRooms);

        $this->assertSame($validationResult, $this->service->validateRoomData($roomPayload));
        $this->assertSame($createResult, $this->service->createRoom($roomPayload, 21));
        $this->assertTrue($this->service->checkRoomPermission(11, 21, 'delete'));
        $this->assertSame($deleteResult, $this->service->deleteRoom(11, 21));
        $this->assertSame($updateResult, $this->service->updateRoom(11, ['name' => 'Room B'], 21));
        $this->assertSame($stats, $this->service->getRoomStats(11));
        $this->assertSame($activeRooms, $this->service->getActiveRooms(21));
    }

    #[Test]
    public function it_delegates_presence_operations_to_the_presence_service(): void
    {
        $statusResult = ['success' => true, 'room_user' => (object) ['user_id' => 8]];
        $joinResult = ['success' => true, 'message' => 'joined'];
        $leaveResult = ['success' => true, 'message' => 'left'];
        $onlineUsers = new Collection([(object) ['id' => 8], (object) ['id' => 9]]);
        $heartbeatResult = ['success' => true];
        $cleanupResult = ['success' => true, 'cleaned_count' => 2];

        $this->presenceService->shouldReceive('updateUserStatus')
            ->once()
            ->with(13, 8, false)
            ->andReturn($statusResult);
        $this->presenceService->shouldReceive('joinRoom')
            ->once()
            ->with(13, 8)
            ->andReturn($joinResult);
        $this->presenceService->shouldReceive('leaveRoom')
            ->once()
            ->with(13, 8)
            ->andReturn($leaveResult);
        $this->presenceService->shouldReceive('getOnlineUsers')
            ->once()
            ->with(13)
            ->andReturn($onlineUsers);
        $this->presenceService->shouldReceive('processHeartbeat')
            ->once()
            ->with(13, 8)
            ->andReturn($heartbeatResult);
        $this->presenceService->shouldReceive('cleanupInactiveUsers')
            ->once()
            ->withNoArgs()
            ->andReturn($cleanupResult);

        $this->assertSame($statusResult, $this->service->updateUserStatus(13, 8, false));
        $this->assertSame($joinResult, $this->service->joinRoom(13, 8));
        $this->assertSame($leaveResult, $this->service->leaveRoom(13, 8));
        $this->assertSame($onlineUsers, $this->service->getOnlineUsers(13));
        $this->assertSame($heartbeatResult, $this->service->processHeartbeat(13, 8));
        $this->assertSame($cleanupResult, $this->service->cleanupInactiveUsers());
    }

    #[Test]
    public function it_delegates_activity_operations_to_the_activity_service(): void
    {
        $activity = ['unique_users' => 5, 'hourly_activity' => []];
        $presenceStats = ['online_users' => 2, 'active_rooms' => 1];

        $this->activityService->shouldReceive('getUserActivity')
            ->once()
            ->with(15, 48)
            ->andReturn($activity);
        $this->activityService->shouldReceive('getPresenceStats')
            ->once()
            ->withNoArgs()
            ->andReturn($presenceStats);
        $this->activityService->shouldReceive('trackRoomActivity')
            ->once()
            ->with(15, 'joined', 8);

        $this->assertSame($activity, $this->service->getUserActivity(15, 48));
        $this->assertSame($presenceStats, $this->service->getPresenceStats());
        $this->service->trackRoomActivity(15, 'joined', 8);
        $this->assertTrue(true);
    }
}
