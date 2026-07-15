<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPaginationService;
use App\Services\Chat\ContentFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class ChatMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChatMessageService(new ChatPaginationService);
    }

    public function test_validate_message_rejects_empty_and_too_long_messages(): void
    {
        $empty = $this->service->validateMessage('   ');
        $tooLong = $this->service->validateMessage(str_repeat('a', 1001));

        $this->assertFalse($empty['valid']);
        $this->assertContains('Message cannot be empty', $empty['errors']);

        $this->assertFalse($tooLong['valid']);
        $this->assertContains('Message cannot exceed 1000 characters', $tooLong['errors']);
    }

    public function test_validate_message_counts_multibyte_characters_by_character_length(): void
    {
        $valid = $this->service->validateMessage(str_repeat('你', 1000));
        $tooLong = $this->service->validateMessage(str_repeat('你', 1001));

        $this->assertTrue($valid['valid']);
        $this->assertFalse($tooLong['valid']);
        $this->assertContains('Message cannot exceed 1000 characters', $tooLong['errors']);
    }

    public function test_sanitize_message_removes_scripts_tags_and_extra_whitespace(): void
    {
        $sanitized = $this->service->sanitizeMessage("<script>alert('x')</script>Hello   <b>world</b>\n\n!");

        $this->assertSame('Hello world !', $sanitized);
    }

    public function test_process_mentions_finds_users_case_insensitively(): void
    {
        $john = User::factory()->create(['name' => 'John.Doe']);
        $jane = User::factory()->create(['name' => 'jane']);

        $mentions = $this->service->processMentions('Hello @john.doe and @JANE');

        $this->assertCount(2, $mentions);
        $this->assertEqualsCanonicalizing(
            [$john->id, $jane->id],
            collect($mentions)->pluck('user_id')->all()
        );
    }

    public function test_process_mentions_supports_unicode_and_preserves_first_seen_order(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $xiaoli = User::factory()->create(['name' => '小李']);

        $mentions = $this->service->processMentions('Hi @Alice then @小李 and again @alice');

        $this->assertSame(
            [$alice->id, $xiaoli->id],
            collect($mentions)->pluck('user_id')->all()
        );
    }

    public function test_format_message_wraps_mentions_and_replaces_emoticons(): void
    {
        $formatted = $this->service->formatMessage('Hi @john :) <3', [
            [
                'user_id' => 7,
                'username' => 'john',
            ],
        ]);

        $this->assertStringContainsString('<mention data-user-id="7">@john</mention>', $formatted);
        $this->assertStringContainsString('😊', $formatted);
        $this->assertStringContainsString('❤️', $formatted);
    }

    public function test_format_message_wraps_unicode_mentions(): void
    {
        $formatted = $this->service->formatMessage('你好 @小李', [
            [
                'user_id' => 8,
                'username' => '小李',
            ],
        ]);

        $this->assertStringContainsString('<mention data-user-id="8">@小李</mention>', $formatted);
    }

    public function test_process_message_persists_filtered_message_and_mentions(): void
    {
        $roomId = 12;
        $user = User::factory()->create();
        $mentioned = User::factory()->create(['name' => 'alice']);

        $this->mock(ContentFilterService::class, function ($mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andReturn([
                    'allowed' => true,
                    'filtered_message' => 'Hello @alice [filtered] 😊',
                    'violations' => [],
                    'actions_taken' => [],
                    'severity' => 'low',
                ]);
        });

        $result = $this->service->processMessage($roomId, $user->id, 'Hello @alice stupid :)');

        $this->assertTrue($result['success']);
        $this->assertSame('Hello @alice [filtered] 😊', $result['original_message']);
        $this->assertCount(1, $result['mentions']);
        $this->assertSame($mentioned->id, $result['mentions'][0]['user_id']);

        $message = $result['message'];
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
        $this->assertStringContainsString('<mention data-user-id="'.$mentioned->id.'">@alice</mention>', $message->message);
    }

    public function test_process_message_returns_blocked_response_when_filter_disallows(): void
    {
        $user = User::factory()->create();

        $this->mock(ContentFilterService::class, function ($mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andReturn([
                    'allowed' => false,
                    'filtered_message' => 'blocked',
                    'violations' => ['spam' => ['severity' => 'high']],
                    'actions_taken' => ['spam_blocked'],
                    'severity' => 'high',
                ]);
        });

        $result = $this->service->processMessage(20, $user->id, 'blocked message');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['blocked']);
        $this->assertSame(['Message blocked by content filter'], $result['errors']);
        $this->assertDatabaseMissing('chat_messages', [
            'room_id' => 20,
            'user_id' => $user->id,
        ]);
    }

    public function test_create_system_message_persists_sanitized_system_message(): void
    {
        $message = $this->service->createSystemMessage(55, '<b>Room created</b>', 9);

        $this->assertNotNull($message);
        $this->assertSame(ChatMessage::TYPE_SYSTEM, $message->message_type);
        $this->assertSame('Room created', $message->message);
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'room_id' => 55,
            'user_id' => 9,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'message' => 'Room created',
        ]);
    }

    public function test_get_message_stats_returns_breakdown_and_top_users(): void
    {
        $roomId = 88;
        $topUser = User::factory()->create(['name' => 'Top User']);
        $otherUser = User::factory()->create(['name' => 'Other User']);

        ChatMessage::factory()->count(3)->create([
            'room_id' => $roomId,
            'user_id' => $topUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
        ChatMessage::factory()->create([
            'room_id' => $roomId,
            'user_id' => $otherUser->id,
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
        ChatMessage::factory()->count(2)->create([
            'room_id' => $roomId,
            'user_id' => $topUser->id,
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $stats = $this->service->getMessageStats($roomId);

        $this->assertSame(6, $stats['total_messages']);
        $this->assertSame(4, $stats['text_messages']);
        $this->assertSame(2, $stats['system_messages']);
        $this->assertSame($topUser->id, $stats['top_users']->first()->user_id);
        $this->assertSame(3, (int) $stats['top_users']->first()->message_count);
    }

    public function test_search_messages_and_recent_messages_delegate_to_pagination_service(): void
    {
        $roomId = 91;
        $user = User::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'message' => 'Searchable needle message',
        ]);
        ChatMessage::factory()->create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'message' => 'Another recent message',
        ]);

        $search = $this->service->searchMessages($roomId, 'needle');
        $recent = $this->service->getRecentMessages($roomId, 10);

        $this->assertCount(1, $search['messages']);
        $this->assertStringContainsString('needle', $search['messages']->first()->message);
        $this->assertCount(2, $recent);
    }

    public function test_search_messages_returns_empty_result_for_blank_query_after_sanitization(): void
    {
        $roomId = 92;
        $user = User::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message_type' => ChatMessage::TYPE_TEXT,
            'message' => 'This should not be returned for an empty search',
        ]);

        $result = $this->service->searchMessages($roomId, '   <b> </b>   ');

        $this->assertCount(0, $result['messages']);
        $this->assertSame('', $result['search_query']);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_cursor']);
    }

    public function test_get_message_history_delegates_to_pagination_service(): void
    {
        $roomId = 42;
        $user = User::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message' => 'Test message',
        ]);

        $result = $this->service->getMessageHistory($roomId, null, 10, 'before');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('messages', $result);
    }

    public function test_get_message_history_paginated_returns_paginator(): void
    {
        $roomId = 43;
        $user = User::factory()->create();

        ChatMessage::factory()->count(3)->create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message' => 'Test message',
        ]);

        $result = $this->service->getMessageHistoryPaginated($roomId);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(3, $result->items());
    }

    public function test_process_message_returns_error_for_invalid_message(): void
    {
        $user = User::factory()->create();

        // Test empty message
        $result = $this->service->processMessage(1, $user->id, '   ');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertContains('Message cannot be empty', $result['errors']);
    }

    public function test_process_message_handles_database_exception(): void
    {
        $user = User::factory()->create();

        // Use event listener to throw exception during model creation
        ChatMessage::creating(function () {
            throw new \Exception('Database error');
        });

        $result = $this->service->processMessage(1, $user->id, 'Valid message');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('Failed to save message', $result['errors'][0]);
    }

    public function test_create_system_message_returns_null_on_exception(): void
    {
        // Mock ChatMessage to throw exception
        ChatMessage::creating(function () {
            throw new \Exception('Database error');
        });

        $result = $this->service->createSystemMessage(1, 'System message');

        $this->assertNull($result);
    }
}
