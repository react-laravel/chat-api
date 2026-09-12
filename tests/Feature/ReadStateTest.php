<?php

namespace Tests\Feature;

use App\Events\Chat\RoomRead;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_cursor_is_monotonic_and_room_list_counts_only_other_users_messages(): void
    {
        Event::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $room = ChatRoom::factory()->create();
        $member = ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);
        $first = ChatMessage::factory()->create(['room_id' => $room->id, 'user_id' => $other->id, 'message_type' => 'text']);
        ChatMessage::factory()->create(['room_id' => $room->id, 'user_id' => $user->id, 'message_type' => 'text']);
        $last = ChatMessage::factory()->create(['room_id' => $room->id, 'user_id' => $other->id, 'message_type' => 'text']);
        Sanctum::actingAs($user);
        $this->getJson('/api/chat/rooms')->assertOk()->assertJsonPath('data.rooms.0.unread_count', 2);
        $this->putJson('/api/chat/rooms/'.$room->id.'/read', ['message_id' => $last->id])->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->putJson('/api/chat/rooms/'.$room->id.'/read', ['message_id' => $first->id])->assertOk()->assertJsonPath('data.last_read_message_id', $last->id);
        $this->assertEquals($last->id, $member->fresh()->last_read_message_id);
        Event::assertDispatched(RoomRead::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-user.'.$user->id.'.notifications');
        $this->getJson('/api/chat/rooms')->assertOk()->assertJsonPath('data.rooms.0.unread_count', 0);
    }

    public function test_read_cursor_cannot_reference_another_room_or_be_set_by_nonmembers(): void
    {
        $room = ChatRoom::factory()->create();
        $user = User::factory()->create();
        $message = ChatMessage::factory()->create(['room_id' => $room->id]);
        Sanctum::actingAs($user);
        $this->putJson('/api/chat/rooms/'.$room->id.'/read', ['message_id' => $message->id])->assertForbidden();
        ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);
        $foreign = ChatMessage::factory()->create();
        $this->putJson('/api/chat/rooms/'.$room->id.'/read', ['message_id' => $foreign->id])->assertUnprocessable();
    }
}
