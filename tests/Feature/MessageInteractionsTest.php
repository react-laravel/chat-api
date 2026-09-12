<?php

namespace Tests\Feature;

use App\Events\Chat\MessageSent;
use App\Events\Chat\MessageUpdated;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChatRoom $room;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->user = User::factory()->create();
        $this->room = ChatRoom::factory()->create(['created_by' => $this->user->id]);
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->user->id, 'is_online' => true]);
        Sanctum::actingAs($this->user);
    }

    private function message(array $attributes = []): ChatMessage
    {
        return ChatMessage::factory()->create(array_merge(['room_id' => $this->room->id, 'user_id' => $this->user->id, 'message_type' => 'text'], $attributes));
    }

    private function endpoint(string $suffix = ''): string
    {
        return '/api/chat/rooms/'.$this->room->id.'/messages'.$suffix;
    }

    public function test_reply_is_persisted_and_returned_in_history_and_broadcast(): void
    {
        $original = $this->message(['message' => 'Original message']);
        $response = $this->postJson($this->endpoint(), ['message' => 'A reply', 'reply_to_id' => $original->id]);
        $response->assertCreated()->assertJsonPath('data.data.reply_to.id', $original->id)->assertJsonPath('data.data.message', 'A reply');
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('data.0.reply_to.message', 'Original message');
        Event::assertDispatched(MessageSent::class, fn ($event) => $event->broadcastWith()['message']['reply_to']['id'] === $original->id);
    }

    public function test_replies_cannot_reference_other_rooms_or_deleted_messages(): void
    {
        $foreign = ChatMessage::factory()->create();
        $this->postJson($this->endpoint(), ['message' => 'Reply', 'reply_to_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');
        $deleted = $this->message();
        $deleted->delete();
        $this->postJson($this->endpoint(), ['message' => 'Reply', 'reply_to_id' => $deleted->id])->assertUnprocessable();
    }

    public function test_deleted_reply_targets_do_not_expose_original_content(): void
    {
        $original = $this->message(['message' => 'Removed content']);
        $reply = $this->message(['reply_to_id' => $original->id]);
        $original->delete();
        $this->getJson($this->endpoint())->assertOk()
            ->assertJsonPath('data.0.id', $reply->id)->assertJsonPath('data.0.reply_to.deleted', true)
            ->assertJsonPath('data.0.reply_to.message', null)->assertJsonPath('data.0.reply_to.user', null);
    }

    public function test_author_can_edit_but_other_members_cannot(): void
    {
        $message = $this->message(['message' => 'Before']);
        $this->patchJson($this->endpoint('/'.$message->id), ['message' => "After\nSecond line"])
            ->assertOk()->assertJsonPath('data.data.message', "After\nSecond line");
        $this->assertNotNull($message->fresh()->edited_at);
        Event::assertDispatched(MessageUpdated::class);
        $other = User::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $other->id]);
        Sanctum::actingAs($other);
        $this->patchJson($this->endpoint('/'.$message->id), ['message' => 'Unauthorized edit'])->assertForbidden();
    }

    public function test_reactions_are_idempotent_and_cancellation_only_affects_the_current_user(): void
    {
        $message = $this->message();
        $endpoint = $this->endpoint('/'.$message->id.'/reactions');
        $this->putJson($endpoint, ['emoji' => '👍'])->assertOk();
        $this->putJson($endpoint, ['emoji' => '👍'])->assertOk()->assertJsonPath('data.data.reactions.0.count', 1);
        $other = User::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $other->id]);
        Sanctum::actingAs($other);
        $this->putJson($endpoint, ['emoji' => '👍'])->assertOk()->assertJsonPath('data.data.reactions.0.count', 2);
        $this->deleteJson($endpoint, ['emoji' => '👍'])->assertOk()->assertJsonPath('data.data.reactions.0.user_ids', [$this->user->id]);
        $this->assertDatabaseCount('chat_message_reactions', 1);
    }

    public function test_nonmembers_cannot_search_edit_or_react(): void
    {
        $message = $this->message();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->endpoint('/search?q=message'))->assertForbidden();
        $this->patchJson($this->endpoint('/'.$message->id), ['message' => 'No'])->assertForbidden();
        $this->putJson($this->endpoint('/'.$message->id.'/reactions'), ['emoji' => '👍'])->assertForbidden();
    }

    public function test_search_finds_older_messages_with_literal_wildcards_without_leaking_other_rooms(): void
    {
        $target = $this->message(['message' => '发布完成 100%_OK', 'created_at' => now()->subDays(2)]);
        ChatMessage::factory()->count(35)->create(['room_id' => $this->room->id, 'user_id' => $this->user->id, 'message' => 'Latest discussion']);
        ChatMessage::factory()->create(['message' => '发布完成 100%_OK']);
        $this->getJson($this->endpoint('/search?q='.urlencode('100%_OK')))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_private_messages_are_only_delivered_to_current_non_banned_members(): void
    {
        $this->room->update(['is_private' => true]);
        $member = User::factory()->create();
        $banned = User::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $member->id]);
        ChatRoomUser::factory()->create(['room_id' => $this->room->id, 'user_id' => $banned->id, 'is_banned' => true, 'banned_until' => null]);
        $event = new MessageSent($this->message());
        $names = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertEqualsCanonicalizing(['private-user.'.$this->user->id.'.notifications', 'private-user.'.$member->id.'.notifications'], $names);
        $this->assertNotContains('private-chat.room.'.$this->room->id, $names);
    }

    public function test_members_cannot_forge_system_messages_or_use_unknown_reactions(): void
    {
        $this->postJson($this->endpoint(), ['message' => 'Fake system event', 'message_type' => 'system'])->assertUnprocessable();
        $message = $this->message();
        $this->putJson($this->endpoint('/'.$message->id.'/reactions'), ['emoji' => '<script>'])->assertUnprocessable();
    }
}
