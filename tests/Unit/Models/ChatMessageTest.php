<?php

namespace Tests\Unit\Models;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ChatRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = ChatRoom::factory()->create([
            'created_by' => $this->user->id,
        ]);
    }

    public function test_chat_message_can_be_created()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Hello, world!',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->assertInstanceOf(ChatMessage::class, $message);
        $this->assertEquals('Hello, world!', $message->message);
        $this->assertEquals(ChatMessage::TYPE_TEXT, $message->message_type);
    }

    public function test_chat_message_has_room_relationship()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Test message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->assertInstanceOf(ChatRoom::class, $message->room);
        $this->assertEquals($this->room->id, $message->room->id);
    }

    public function test_chat_message_has_user_relationship()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Test message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->assertInstanceOf(User::class, $message->user);
        $this->assertEquals($this->user->id, $message->user->id);
    }

    public function test_text_messages_scope()
    {
        // Create text message
        ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Text message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        // Create system message
        ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'System message',
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $textMessages = ChatMessage::textMessages()->get();

        $this->assertCount(1, $textMessages);
        $this->assertEquals(ChatMessage::TYPE_TEXT, $textMessages->first()->message_type);
    }

    public function test_system_messages_scope()
    {
        // Create text message
        ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Text message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        // Create system message
        ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'System message',
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $systemMessages = ChatMessage::systemMessages()->get();

        $this->assertCount(1, $systemMessages);
        $this->assertEquals(ChatMessage::TYPE_SYSTEM, $systemMessages->first()->message_type);
    }

    public function test_for_room_scope()
    {
        $room2 = ChatRoom::factory()->create();

        // Create messages in different rooms
        ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Message in room 1',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        ChatMessage::create([
            'room_id' => $room2->id,
            'user_id' => $this->user->id,
            'message' => 'Message in room 2',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $room1Messages = ChatMessage::forRoom($this->room->id)->get();

        $this->assertCount(1, $room1Messages);
        $this->assertEquals($this->room->id, $room1Messages->first()->room_id);
    }

    public function test_is_text_message_method()
    {
        $textMessage = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Text message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $systemMessage = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'System message',
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $this->assertTrue($textMessage->isTextMessage());
        $this->assertFalse($systemMessage->isTextMessage());
    }

    public function test_is_system_message_method()
    {
        $textMessage = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Text message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $systemMessage = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'System message',
            'message_type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $this->assertTrue($systemMessage->isSystemMessage());
        $this->assertFalse($textMessage->isSystemMessage());
    }

    public function test_message_type_constants()
    {
        $this->assertEquals('text', ChatMessage::TYPE_TEXT);
        $this->assertEquals('system', ChatMessage::TYPE_SYSTEM);
    }

    public function test_chat_message_can_be_updated()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Original message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $message->update([
            'message' => 'Updated message',
        ]);

        $this->assertEquals('Updated message', $message->fresh()->message);
    }

    public function test_chat_message_can_be_deleted()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Test message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $messageId = $message->id;
        $message->delete();

        $this->assertSoftDeleted('chat_messages', ['id' => $messageId]);
    }

    public function test_chat_message_has_correct_timestamps()
    {
        $message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Test message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->assertNotNull($message->created_at);
        $this->assertNotNull($message->updated_at);
        $this->assertInstanceOf(Carbon::class, $message->created_at);
        $this->assertInstanceOf(Carbon::class, $message->updated_at);
    }

    public function test_chat_message_fillable_attributes()
    {
        $messageData = [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'message' => 'Test message',
            'message_type' => ChatMessage::TYPE_TEXT,
        ];

        $message = ChatMessage::create($messageData);

        $this->assertEquals($messageData['room_id'], $message->room_id);
        $this->assertEquals($messageData['user_id'], $message->user_id);
        $this->assertEquals($messageData['message'], $message->message);
        $this->assertEquals($messageData['message_type'], $message->message_type);
    }
}
