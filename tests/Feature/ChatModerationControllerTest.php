<?php

namespace Tests\Feature;

use App\Events\Chat\MessageDeleted;
use App\Events\Chat\UserBanned;
use App\Events\Chat\UserMuted;
use App\Events\Chat\UserUnbanned;
use App\Events\Chat\UserUnmuted;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatModerationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private User $regularUser;

    private ChatRoom $room;

    private ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->moderator = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
        $this->room = ChatRoom::factory()->create([
            'created_by' => $this->moderator->id,
            'is_active' => true,
        ]);

        // Add users to room
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->moderator->id,
            'is_online' => true,
        ]);
        ChatRoomUser::create([
            'room_id' => $this->room->id,
            'user_id' => $this->regularUser->id,
            'is_online' => true,
        ]);

        // Create a test message
        $this->message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->regularUser->id,
            'message' => 'Test message',
            'message_type' => 'text',
        ]);

        Sanctum::actingAs($this->moderator);
    }

    public function test_delete_message_successfully()
    {
        $response = $this->deleteJson("/api/chat/moderation/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'reason' => 'Inappropriate content',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Message deleted successfully');
        $response->assertJsonPath('data.action', 'delete_message');

        $this->assertSoftDeleted('chat_messages', ['id' => $this->message->id]);
        $this->assertDatabaseHas('chat_moderation_actions', [
            'room_id' => $this->room->id,
            'moderator_id' => $this->moderator->id,
            'target_user_id' => $this->regularUser->id,
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
            'reason' => 'Inappropriate content',
        ]);

        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_delete_message_unauthorized()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->deleteJson("/api/chat/moderation/rooms/{$this->room->id}/messages/{$this->message->id}");

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'You are not authorized to moderate this room',
        ]);
    }

    public function test_delete_message_without_reason()
    {
        $response = $this->deleteJson("/api/chat/moderation/rooms/{$this->room->id}/messages/{$this->message->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('chat_moderation_actions', [
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
            'reason' => null,
        ]);
    }

    public function test_mute_user_successfully()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/mute", [
            'reason' => 'Spam',
            'duration' => 3600,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User muted successfully');
        $response->assertJsonPath('data.action', 'mute_user');

        $this->assertDatabaseHas('chat_moderation_actions', [
            'room_id' => $this->room->id,
            'moderator_id' => $this->moderator->id,
            'target_user_id' => $this->regularUser->id,
            'action_type' => ChatModerationAction::ACTION_MUTE_USER,
            'reason' => 'Spam',
        ]);

        Event::assertDispatched(UserMuted::class);
    }

    public function test_mute_user_unauthorized()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->moderator->id}/mute");

        $response->assertStatus(403);
    }

    public function test_mute_user_without_duration()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/mute", [
            'reason' => 'Spam',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('chat_moderation_actions', [
            'action_type' => ChatModerationAction::ACTION_MUTE_USER,
        ]);
    }

    public function test_unmute_user_successfully()
    {
        // First mute the user
        $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/mute");

        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/unmute", [
            'reason' => 'Appeal accepted',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User unmuted successfully');
        $response->assertJsonPath('data.action', 'unmute_user');

        $this->assertDatabaseHas('chat_moderation_actions', [
            'action_type' => ChatModerationAction::ACTION_UNMUTE_USER,
            'reason' => 'Appeal accepted',
        ]);

        Event::assertDispatched(UserUnmuted::class);
    }

    public function test_unmute_user_not_muted()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/unmute");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'User is not muted');
    }

    public function test_ban_user_successfully()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/ban", [
            'reason' => 'Repeated violations',
            'duration' => 86400,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User banned successfully');
        $response->assertJsonPath('data.action', 'ban_user');

        $this->assertDatabaseHas('chat_moderation_actions', [
            'action_type' => ChatModerationAction::ACTION_BAN_USER,
            'reason' => 'Repeated violations',
        ]);

        Event::assertDispatched(UserBanned::class);
    }

    public function test_ban_user_unauthorized()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->moderator->id}/ban");

        $response->assertStatus(403);
    }

    public function test_unban_user_successfully()
    {
        // First ban the user
        $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/ban");

        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/unban", [
            'reason' => 'Appeal accepted',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User unbanned successfully');
        $response->assertJsonPath('data.action', 'unban_user');

        $this->assertDatabaseHas('chat_moderation_actions', [
            'action_type' => ChatModerationAction::ACTION_UNBAN_USER,
            'reason' => 'Appeal accepted',
        ]);

        Event::assertDispatched(UserUnbanned::class);
    }

    public function test_unban_user_not_banned()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/unban");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'User is not banned');
    }

    public function test_get_moderation_actions()
    {
        // Create some moderation actions
        ChatModerationAction::create([
            'room_id' => $this->room->id,
            'moderator_id' => $this->moderator->id,
            'target_user_id' => $this->regularUser->id,
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
            'reason' => 'Test reason',
        ]);

        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/actions");

        $response->assertStatus(200);
        // getModerationActions returns raw Spatie jsonPaginate via response()->json(), not Controller::success()
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'room_id',
                    'moderator_id',
                    'target_user_id',
                    'action_type',
                    'reason',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.action_type', ChatModerationAction::ACTION_DELETE_MESSAGE);
    }

    public function test_get_moderation_actions_supports_filters()
    {
        ChatModerationAction::create([
            'room_id' => $this->room->id,
            'moderator_id' => $this->moderator->id,
            'target_user_id' => $this->regularUser->id,
            'action_type' => ChatModerationAction::ACTION_MUTE_USER,
            'reason' => 'Muted for spam',
        ]);

        ChatModerationAction::create([
            'room_id' => $this->room->id,
            'moderator_id' => $this->moderator->id,
            'target_user_id' => $this->moderator->id,
            'action_type' => ChatModerationAction::ACTION_BAN_USER,
            'reason' => 'Should be filtered out',
        ]);

        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/actions?action_type=mute_user&target_user_id={$this->regularUser->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.action_type', ChatModerationAction::ACTION_MUTE_USER);
        $response->assertJsonPath('data.0.target_user_id', $this->regularUser->id);
    }

    public function test_get_moderation_actions_unauthorized()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/actions");

        $response->assertStatus(403);
    }

    public function test_get_user_moderation_status()
    {
        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->regularUser->id}/status");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user',
                'moderation_status' => [
                    'is_muted',
                    'muted_until',
                    'muted_by',
                    'is_banned',
                    'banned_until',
                    'banned_by',
                    'can_send_messages',
                ],
            ],
        ]);
    }

    public function test_get_user_moderation_status_unauthorized()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->moderator->id}/status");

        $response->assertStatus(403);
    }

    public function test_delete_message_with_invalid_message_id()
    {
        $response = $this->deleteJson("/api/chat/moderation/rooms/{$this->room->id}/messages/999");

        $response->assertStatus(404);
    }

    public function test_delete_message_with_invalid_room_id()
    {
        $response = $this->deleteJson("/api/chat/moderation/rooms/999/messages/{$this->message->id}");

        $response->assertStatus(404);
    }

    public function test_mute_user_with_invalid_user_id()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/999/mute");

        $response->assertStatus(404);
    }

    public function test_ban_user_with_invalid_user_id()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/999/ban");

        $response->assertStatus(404);
    }

    public function test_mute_user_rejects_self_moderation()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->moderator->id}/mute");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'You cannot mute yourself');
    }

    public function test_ban_user_rejects_self_moderation()
    {
        $response = $this->postJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$this->moderator->id}/ban");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'You cannot ban yourself');
    }

    public function test_get_user_moderation_status_returns_not_found_when_user_is_not_in_room()
    {
        $outsider = User::factory()->create();

        $response = $this->getJson("/api/chat/moderation/rooms/{$this->room->id}/users/{$outsider->id}/status");

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'User is not in this room');
    }
}
