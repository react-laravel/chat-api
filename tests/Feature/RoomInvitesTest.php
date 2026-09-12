<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomInvite;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomInvitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_invite_adds_membership_once_and_can_be_revoked(): void
    {
        $owner = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $owner->id, 'is_private' => true]);
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/chat/rooms/'.$room->id.'/invites', ['max_uses' => 1]);
        $created->assertCreated()->assertJsonMissingPath('data.invite.token_hash');
        $token = $created->json('data.token');
        $id = $created->json('data.invite.id');
        $this->getJson('/api/chat/rooms/'.$room->id.'/invites')->assertOk()->assertJsonMissingPath('data.invites.0.token_hash');
        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertOk()->assertJsonPath('data.room.id', $room->id);
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertOk();
        $this->assertDatabaseHas('chat_room_invites', ['id' => $id, 'uses_count' => 1]);
        $this->assertDatabaseHas('chat_room_users', ['room_id' => $room->id, 'user_id' => $member->id]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertStatus(410);
        $this->postJson('/api/chat/rooms/'.$room->id.'/invites')->assertForbidden();
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/chat/rooms/'.$room->id.'/invites/'.$id)->assertOk();
        Sanctum::actingAs($member);
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertStatus(410);
    }

    public function test_expired_invites_and_banned_members_are_rejected(): void
    {
        $owner = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $owner->id, 'is_private' => true]);
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/chat/rooms/'.$room->id.'/invites');
        $token = $created->json('data.token');
        $member = User::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $member->id, 'is_banned' => true, 'banned_until' => now()->addDay()]);
        Sanctum::actingAs($member);
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertForbidden();
        ChatRoomInvite::first()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/chat/invites/accept', ['token' => $token])->assertStatus(410);
    }
}
