<?php

namespace Tests\Unit\Services;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use App\Services\Chat\ChatPaginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatPaginationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatPaginationService $paginationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paginationService = new ChatPaginationService;
    }

    #[Test]
    public function it_gets_messages_with_cursor_pagination()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with different timestamps
        $messages = ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('next_cursor', $result);
        $this->assertArrayHasKey('prev_cursor', $result);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertCount(10, $result['messages']);
    }

    #[Test]
    public function it_gets_messages_with_limit()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create more messages than limit
        ChatMessage::factory()->count(15)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id, null, 5);

        $this->assertCount(5, $result['messages']);
        $this->assertTrue($result['has_more']);
    }

    #[Test]
    public function it_gets_messages_before_cursor()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages
        $messages = ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Get first page
        $firstResult = $this->paginationService->getMessagesCursor($room->id, null, 5);

        // Get second page using cursor
        $secondResult = $this->paginationService->getMessagesCursor(
            $room->id,
            $firstResult['next_cursor'],
            5,
            'before'
        );

        $this->assertCount(5, $secondResult['messages']);
        $this->assertNotEquals($firstResult['messages']->first()->id, $secondResult['messages']->first()->id);
    }

    #[Test]
    public function it_gets_messages_after_cursor()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages
        $messages = ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Get first page
        $firstResult = $this->paginationService->getMessagesCursor($room->id, null, 5);

        // Get newer messages using cursor
        $secondResult = $this->paginationService->getMessagesCursor(
            $room->id,
            $firstResult['prev_cursor'],
            5,
            'after'
        );

        $this->assertCount(4, $secondResult['messages']);
    }

    #[Test]
    public function it_gets_recent_messages()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages
        ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getRecentMessages($room->id, 5);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertCount(5, $result['messages']);
    }

    #[Test]
    public function it_gets_messages_after_specific_message()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages
        $messages = ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $middleMessage = $messages[4]; // 5th message

        $result = $this->paginationService->getMessagesAfter($room->id, $middleMessage->id, 5);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertGreaterThan(0, $result->count());

        // All returned messages should have IDs greater than the middle message
        foreach ($result as $message) {
            $this->assertGreaterThan($middleMessage->id, $message->id);
        }
    }

    #[Test]
    public function it_searches_messages()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with specific content
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello world test message',
        ]);

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Another message without keyword',
        ]);

        $result = $this->paginationService->searchMessages($room->id, 'test');

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('next_cursor', $result);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertCount(1, $result['messages']);
        $this->assertStringContainsString('test', $result['messages']->first()->message);
    }

    #[Test]
    public function it_returns_empty_search_for_no_matches()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages without search term
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello world',
        ]);

        $result = $this->paginationService->searchMessages($room->id, 'nonexistent');

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(0, $result['messages']);
        $this->assertFalse($result['has_more']);
    }

    #[Test]
    public function it_gets_message_statistics()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages over the last few days
        ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $result = $this->paginationService->getMessageStatistics($room->id, 7);

        $this->assertArrayHasKey('total_messages', $result);
        $this->assertArrayHasKey('messages_per_day', $result);
        $this->assertArrayHasKey('active_users', $result);
        $this->assertEquals(10, $result['total_messages']);
    }

    #[Test]
    public function it_handles_invalid_cursor()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create some messages
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id, 'invalid-cursor');

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(5, $result['messages']);
    }

    #[Test]
    public function it_respects_max_page_size()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create more messages than the service max page size
        ChatMessage::factory()->count(150)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Try to get more than max page size
        $result = $this->paginationService->getMessagesCursor($room->id, null, 200);

        $this->assertCount(100, $result['messages']); // Should be limited to max page size
    }

    #[Test]
    public function it_handles_empty_room()
    {
        $room = ChatRoom::factory()->create();

        $result = $this->paginationService->getMessagesCursor($room->id);

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(0, $result['messages']);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_cursor']);
    }

    #[Test]
    public function it_generates_correct_cursors()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->count(2)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id, null, 1);

        $this->assertNotNull($result['next_cursor']);
        $this->assertNotNull($result['prev_cursor']);
        $this->assertIsString($result['next_cursor']);
        $this->assertIsString($result['prev_cursor']);
    }

    #[Test]
    public function it_handles_search_with_cursor()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with search term
        ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test message content',
        ]);

        $result = $this->paginationService->searchMessages($room->id, 'test', null, 5);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('next_cursor', $result);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertCount(5, $result['messages']);
        $this->assertTrue($result['has_more']);
    }

    #[Test]
    public function it_returns_empty_collection_when_message_not_found_in_get_messages_after()
    {
        $room = ChatRoom::factory()->create();

        $result = $this->paginationService->getMessagesAfter($room->id, 99999);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    #[Test]
    public function it_gets_message_statistics_for_different_days()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages for different days
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
        ]);

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now()->subDays(1),
        ]);

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $result = $this->paginationService->getMessageStatistics($room->id, 3);

        $this->assertArrayHasKey('total_messages', $result);
        $this->assertArrayHasKey('messages_per_day', $result);
        $this->assertArrayHasKey('active_users', $result);
        $this->assertEquals(3, $result['total_messages']);
        $this->assertEquals(1, $result['active_users']);
    }

    #[Test]
    public function it_handles_malformed_cursor()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create some messages
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        // Test with malformed base64
        $result = $this->paginationService->getMessagesCursor($room->id, 'not-valid-base64!!!');

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(5, $result['messages']);
    }

    #[Test]
    public function it_respects_max_page_size_in_recent_messages()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create more messages than max page size
        ChatMessage::factory()->count(150)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getRecentMessages($room->id, 200);

        $this->assertCount(100, $result['messages']);
    }

    #[Test]
    public function it_searches_messages_with_pagination()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with search term
        ChatMessage::factory()->count(30)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'searchable keyword here',
            'message_type' => 'text',
        ]);

        $firstPage = $this->paginationService->searchMessages($room->id, 'keyword', null, 20);

        $this->assertCount(20, $firstPage['messages']);
        $this->assertTrue($firstPage['has_more']);
        $this->assertNotNull($firstPage['next_cursor']);

        // Get second page
        $secondPage = $this->paginationService->searchMessages(
            $room->id,
            'keyword',
            $firstPage['next_cursor'],
            20
        );

        $this->assertCount(10, $secondPage['messages']);
        $this->assertFalse($secondPage['has_more']);
    }

    #[Test]
    public function it_only_searches_text_messages()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create text message with keyword
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test keyword',
            'message_type' => 'text',
        ]);

        // Create system message with keyword
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test keyword',
            'message_type' => 'system',
        ]);

        $result = $this->paginationService->searchMessages($room->id, 'keyword');

        $this->assertCount(1, $result['messages']);
        $this->assertEquals('text', $result['messages']->first()->message_type);
    }

    #[Test]
    public function it_gets_message_statistics_with_multiple_active_users()
    {
        $room = ChatRoom::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // Create messages from different users
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user1->id,
            'created_at' => now(),
        ]);

        ChatMessage::factory()->count(3)->create([
            'room_id' => $room->id,
            'user_id' => $user2->id,
            'created_at' => now(),
        ]);

        ChatMessage::factory()->count(2)->create([
            'room_id' => $room->id,
            'user_id' => $user3->id,
            'created_at' => now(),
        ]);

        $result = $this->paginationService->getMessageStatistics($room->id, 7);

        $this->assertEquals(10, $result['total_messages']);
        $this->assertEquals(3, $result['active_users']);
    }

    #[Test]
    public function it_searches_with_special_characters()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with special characters
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello @user #tag %special',
        ]);

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Regular message without special',
        ]);

        $result = $this->paginationService->searchMessages($room->id, '@user');

        $this->assertCount(1, $result['messages']);
        $this->assertStringContainsString('@user', $result['messages']->first()->message);
    }

    #[Test]
    public function it_searches_with_unicode_characters()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with unicode characters
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello 你好 مرحبا мир',
        ]);

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'English only message',
        ]);

        $result = $this->paginationService->searchMessages($room->id, '你好');

        $this->assertCount(1, $result['messages']);
        $this->assertStringContainsString('你好', $result['messages']->first()->message);
    }

    #[Test]
    public function it_handles_search_with_very_long_query()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $longQuery = str_repeat('test ', 100); // Very long search query
        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test test test',
        ]);

        $result = $this->paginationService->searchMessages($room->id, $longQuery);

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(0, $result['messages']);
    }

    #[Test]
    public function it_handles_statistics_with_no_messages_in_period()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages outside the period
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now()->subDays(10),
        ]);

        $result = $this->paginationService->getMessageStatistics($room->id, 3);

        $this->assertEquals(0, $result['total_messages']);
        $this->assertEquals(0, $result['active_users']);
        $this->assertEmpty($result['messages_per_day']);
    }

    #[Test]
    public function it_preserves_message_order_in_pagination()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        // Create messages with specific timestamps
        $message1 = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now()->subHours(2),
        ]);

        $message2 = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now()->subHours(1),
        ]);

        $message3 = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id, null, 10);

        $this->assertCount(3, $result['messages']);
        $this->assertEquals($message1->id, $result['messages'][0]->id);
        $this->assertEquals($message2->id, $result['messages'][1]->id);
        $this->assertEquals($message3->id, $result['messages'][2]->id);
    }

    #[Test]
    public function it_handles_pagination_with_same_timestamp()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $timestamp = now();

        // Create messages with same timestamp
        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => $timestamp,
        ]);

        $firstResult = $this->paginationService->getMessagesCursor($room->id, null, 2);
        $this->assertCount(2, $firstResult['messages']);
        $this->assertTrue($firstResult['has_more']);

        $secondResult = $this->paginationService->getMessagesCursor(
            $room->id,
            $firstResult['next_cursor'],
            2,
            'before'
        );

        $this->assertCount(2, $secondResult['messages']);
    }

    #[Test]
    public function it_returns_search_query_in_results()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'search term here',
        ]);

        $result = $this->paginationService->searchMessages($room->id, 'search');

        $this->assertArrayHasKey('search_query', $result);
        $this->assertEquals('search', $result['search_query']);
    }

    #[Test]
    public function it_handles_case_insensitive_search()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'Hello World Test',
        ]);

        $resultLower = $this->paginationService->searchMessages($room->id, 'hello');
        $resultUpper = $this->paginationService->searchMessages($room->id, 'WORLD');

        $this->assertCount(1, $resultLower['messages']);
        $this->assertCount(1, $resultUpper['messages']);
    }

    #[Test]
    public function it_handles_messages_after_with_limit()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        $messages = ChatMessage::factory()->count(20)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $midMessage = $messages[10];
        $result = $this->paginationService->getMessagesAfter($room->id, $midMessage->id, 5);

        $this->assertLessThanOrEqual(5, $result->count());
    }

    #[Test]
    public function it_returns_zero_limit_page()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->count(10)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);

        $result = $this->paginationService->getMessagesCursor($room->id, null, 0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('messages', $result);
    }

    #[Test]
    public function it_handles_search_with_empty_string()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test message',
        ]);

        $result = $this->paginationService->searchMessages($room->id, '');

        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(0, $result['messages']);
        $this->assertSame('', $result['search_query']);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_cursor']);
    }

    #[Test]
    public function it_handles_search_with_only_whitespace()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => 'test message',
        ]);

        $result = $this->paginationService->searchMessages($room->id, '   ');

        $this->assertCount(0, $result['messages']);
        $this->assertSame('', $result['search_query']);
        $this->assertFalse($result['has_more']);
    }

    #[Test]
    public function it_handles_negative_days_in_statistics()
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();

        ChatMessage::factory()->count(5)->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $result = $this->paginationService->getMessageStatistics($room->id, -1);

        $this->assertArrayHasKey('total_messages', $result);
        $this->assertArrayHasKey('messages_per_day', $result);
    }
}
