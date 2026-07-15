<?php

namespace Tests\Unit\Resources\Chat;

use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Chat\ChatMessage;
use Tests\TestCase;

class ChatMessageResourceTest extends TestCase
{
    public function test_chat_message_resource_to_array(): void
    {
        $message = new ChatMessage;
        $message->id = 1;
        $message->message = 'Hello world';
        $message->user_id = 1;
        $message->room_id = 1;
        $message->message_type = 'text';
        $message->created_at = now();

        $resource = new ChatMessageResource($message);
        $array = $resource->toArray(request());

        $this->assertEquals(1, $array['id']);
        $this->assertEquals('Hello world', $array['message']);
    }

    public function test_chat_message_resource_includes_timestamps(): void
    {
        $message = new ChatMessage;
        $message->id = 1;
        $message->message = 'Test';
        $message->user_id = 1;
        $message->room_id = 1;
        $message->message_type = 'text';
        $message->created_at = now();

        $resource = new ChatMessageResource($message);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('created_at', $array);
    }

    public function test_chat_message_resource_includes_room_and_user_ids(): void
    {
        $message = new ChatMessage;
        $message->id = 1;
        $message->message = 'Test';
        $message->user_id = 1;
        $message->room_id = 1;
        $message->message_type = 'text';
        $message->created_at = now();

        $resource = new ChatMessageResource($message);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('room_id', $array);
        $this->assertArrayHasKey('user_id', $array);
    }
}
