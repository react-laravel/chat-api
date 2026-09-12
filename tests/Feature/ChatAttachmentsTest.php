<?php

namespace Tests\Feature;

use App\Models\Chat\ChatAttachment;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachment_is_private_until_sent_and_becomes_unavailable_when_message_is_deleted(): void
    {
        Storage::fake('local');
        Event::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = ChatRoom::factory()->create(['created_by' => $owner->id]);
        foreach ([$owner, $member] as $user) {
            ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $user->id, 'is_online' => true]);
        }
        Sanctum::actingAs($owner);
        $uploaded = $this->postJson('/api/chat/rooms/'.$room->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('社区笔记.txt', 'A short community note.')]);
        $uploaded->assertCreated()->assertJsonMissingPath('data.attachment.path');
        $attachment = $uploaded->json('data.attachment');
        Sanctum::actingAs($member);
        $this->get($attachment['url'])->assertForbidden();
        $this->postJson('/api/chat/rooms/'.$room->id.'/messages', ['attachment_ids' => [$attachment['id']]])->assertUnprocessable();
        Sanctum::actingAs($owner);
        $sent = $this->postJson('/api/chat/rooms/'.$room->id.'/messages', ['attachment_ids' => [$attachment['id']]]);
        $sent->assertCreated()->assertJsonPath('data.data.attachments.0.name', '社区笔记.txt');
        $this->getJson('/api/chat/rooms/'.$room->id.'/attachments/drafts')->assertJsonCount(0, 'data.attachments');
        Sanctum::actingAs($member);
        $this->get($attachment['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Sanctum::actingAs(User::factory()->create());
        $this->get($attachment['url'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/chat/rooms/'.$room->id.'/messages/'.$sent->json('data.data.id'))->assertOk();
        $this->get($attachment['url'])->assertNotFound();
    }

    public function test_upload_retry_reuses_the_same_attachment(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);
        Sanctum::actingAs($user);
        $id = '8c9a85b5-a98d-48f5-93e0-bfd611dc28a1';
        $first = $this->postJson('/api/chat/rooms/'.$room->id.'/attachments', ['upload_id' => $id, 'file' => UploadedFile::fake()->createWithContent('note.txt', 'A note.')]);
        $second = $this->postJson('/api/chat/rooms/'.$room->id.'/attachments', ['upload_id' => $id, 'file' => UploadedFile::fake()->createWithContent('note.txt', 'A note.')]);
        $first->assertCreated();
        $second->assertOk()->assertJsonPath('data.attachment.id', $first->json('data.attachment.id'));
        $this->assertDatabaseCount('chat_attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_upload_limits_and_draft_cleanup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $room = ChatRoom::factory()->create();
        ChatRoomUser::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->postJson('/api/chat/rooms/'.$room->id.'/attachments', ['file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/chat/rooms/'.$room->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('unsafe.html', '<script>alert(1)</script>')])->assertUnprocessable();
        Storage::disk('local')->put('chat/old.txt', 'old');
        Storage::disk('local')->put('chat/keep.txt', 'keep');
        $old = ChatAttachment::create(['room_id' => $room->id, 'user_id' => $user->id, 'path' => 'chat/old.txt', 'original_name' => 'old.txt', 'mime_type' => 'text/plain', 'size' => 3]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $current = ChatAttachment::create(['room_id' => $room->id, 'user_id' => $user->id, 'path' => 'chat/keep.txt', 'original_name' => 'keep.txt', 'mime_type' => 'text/plain', 'size' => 4]);
        $this->artisan('chat:cleanup-attachments')->assertSuccessful();
        Storage::disk('local')->assertMissing('chat/old.txt');
        Storage::disk('local')->assertExists('chat/keep.txt');
        $this->assertDatabaseHas('chat_attachments', ['id' => $current->id]);
    }
}
