<?php

namespace Tests\Feature;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReport;
use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $admin;

    protected ChatRoom $room;

    protected ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        // Create system user with ID 1 for auto-moderation
        User::factory()->create(['id' => 1, 'name' => 'System', 'email' => 'system@example.com']);

        $this->user = User::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->room = ChatRoom::factory()->create(['created_by' => $this->admin->id]);

        ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->admin->id,
            'is_online' => true,
        ]);
        ChatRoomUser::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'is_online' => true,
        ]);

        // Create a message from admin that user can report
        $this->message = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $this->admin->id,
            'message' => 'This is a test message',
            'message_type' => 'text',
        ]);
    }

    public function test_user_can_report_message()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
            'reason' => 'This message contains inappropriate content',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'report' => [
                        'id',
                        'message_id',
                        'reported_by',
                        'room_id',
                        'report_type',
                        'reason',
                        'status',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('chat_message_reports', [
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);
    }

    public function test_user_cannot_report_own_message()
    {
        Sanctum::actingAs($this->admin); // Admin trying to report their own message

        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Testing self-report',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You cannot report your own message',
            ]);
    }

    public function test_user_cannot_report_same_message_twice()
    {
        Sanctum::actingAs($this->user);

        // First report
        $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'First report',
        ])->assertStatus(201);

        // Second report should fail
        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'reason' => 'Second report',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You have already reported this message',
            ]);
    }

    public function test_admin_can_view_room_reports()
    {
        // Create a report
        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Test report',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/chat/reports/rooms/{$this->room->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'reports' => [
                        '*' => [
                            'id',
                            'message_id',
                            'reported_by',
                            'report_type',
                            'reason',
                            'status',
                            'reporter',
                            'message',
                        ],
                    ],
                    'pagination',
                ],
            ]);
    }

    public function test_non_admin_cannot_view_reports()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/chat/reports/rooms/{$this->room->id}");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You are not authorized to view reports for this room',
            ]);
    }

    public function test_admin_can_review_report()
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
            'reason' => 'Test report',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'resolve',
            'notes' => 'Report reviewed and resolved',
            'delete_message' => false,
            'mute_user' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'report',
                    'action',
                    'actions_performed',
                ],
            ]);

        $this->assertDatabaseHas('chat_message_reports', [
            'id' => $report->id,
            'status' => ChatMessageReport::STATUS_RESOLVED,
            'reviewed_by' => $this->admin->id,
            'review_notes' => 'Report reviewed and resolved',
        ]);
    }

    public function test_non_moderator_cannot_review_report(): void
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->admin->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'resolve',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'You are not authorized to review this report');
    }

    public function test_review_report_returns_500_when_transaction_fails(): void
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \Exception('review transaction failed'));

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'resolve',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to review report')
            ->assertJsonPath('success', false);
    }

    public function test_report_stats_for_admin()
    {
        // Create some test reports
        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Spam report',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/chat/reports/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_reports',
                    'pending_reports',
                    'resolved_reports',
                    'dismissed_reports',
                    'report_types',
                    'severity_breakdown',
                    'top_reporters',
                    'top_reported_users',
                    'content_filter',
                    'period_days',
                ],
            ]);
    }

    public function test_admin_can_view_all_reports()
    {
        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Global report listing',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/chat/reports');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'reports' => [
                        '*' => [
                            'id',
                            'room_id',
                            'report_type',
                            'status',
                            'reporter',
                            'room',
                        ],
                    ],
                    'pagination',
                ],
            ]);
    }

    public function test_non_admin_cannot_view_all_reports()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/chat/reports');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You are not authorized to view all reports',
            ]);
    }

    public function test_admin_can_filter_room_reports()
    {
        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Should remain after filter',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => User::factory()->create()->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'reason' => 'Should be filtered out',
            'status' => ChatMessageReport::STATUS_RESOLVED,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/chat/reports/rooms/{$this->room->id}?status=pending&report_type=spam");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.reports');
        $response->assertJsonPath('data.reports.0.report_type', ChatMessageReport::TYPE_SPAM);
        $response->assertJsonPath('data.reports.0.status', ChatMessageReport::STATUS_PENDING);
    }

    public function test_admin_can_dismiss_report_and_apply_actions()
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'reason' => 'Dismiss with actions',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'dismiss',
            'notes' => 'Handled manually',
            'delete_message' => true,
            'mute_user' => true,
            'mute_duration' => 30,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.action', 'dismiss');
        $response->assertJsonPath('data.actions_performed.0', 'message_deleted');
        $response->assertJsonPath('data.actions_performed.1', 'user_muted');

        $this->assertDatabaseHas('chat_message_reports', [
            'id' => $report->id,
            'status' => ChatMessageReport::STATUS_DISMISSED,
            'reviewed_by' => $this->admin->id,
        ]);
        $this->assertSoftDeleted('chat_messages', [
            'id' => $this->message->id,
        ]);
        $this->assertDatabaseHas('chat_room_users', [
            'room_id' => $this->room->id,
            'user_id' => $this->admin->id,
            'is_muted' => true,
            'muted_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_escalate_report()
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_MISINFORMATION,
            'reason' => 'Needs escalation',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'escalate',
            'notes' => 'Escalated to moderators',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.action', 'escalate');

        $this->assertDatabaseHas('chat_message_reports', [
            'id' => $report->id,
            'status' => ChatMessageReport::STATUS_REVIEWED,
            'reviewed_by' => $this->admin->id,
            'review_notes' => 'Escalated to moderators',
        ]);
    }

    public function test_non_admin_cannot_view_global_report_stats()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/chat/reports/stats');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You are not authorized to view global report stats',
            ]);
    }

    public function test_admin_can_view_room_specific_report_stats()
    {
        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Room-specific stats',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/chat/reports/stats?room_id={$this->room->id}&days=30");

        $response->assertStatus(200);
        $response->assertJsonPath('data.period_days', 30);
        $response->assertJsonPath('data.total_reports', 1);
        $response->assertJsonPath('data.report_types.spam', 1);
    }

    public function test_non_moderator_cannot_view_room_specific_report_stats()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/chat/reports/stats?room_id={$this->room->id}&days=7");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'You are not authorized to view stats for this room');
    }

    public function test_admin_can_filter_all_reports_by_status_type_and_room(): void
    {
        $otherRoom = ChatRoom::factory()->create(['created_by' => $this->admin->id]);
        $otherMessage = ChatMessage::factory()->create([
            'room_id' => $otherRoom->id,
            'user_id' => $this->user->id,
        ]);

        ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'keep this one',
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        ChatMessageReport::create([
            'message_id' => $otherMessage->id,
            'reported_by' => $this->user->id,
            'room_id' => $otherRoom->id,
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'reason' => 'filter out',
            'status' => ChatMessageReport::STATUS_RESOLVED,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/chat/reports?'.http_build_query([
            'status' => ChatMessageReport::STATUS_PENDING,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'room_id' => $this->room->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.reports');
        $response->assertJsonPath('data.reports.0.room_id', $this->room->id);
        $response->assertJsonPath('data.reports.0.status', ChatMessageReport::STATUS_PENDING);
        $response->assertJsonPath('data.reports.0.report_type', ChatMessageReport::TYPE_SPAM);
    }

    public function test_invalid_report_type_is_rejected()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => 'not_a_valid_type',
            'reason' => 'Invalid payload',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['report_type']);
    }

    public function test_auto_moderation_on_multiple_reports()
    {
        // Create 3 reports for the same message to trigger auto-moderation
        for ($i = 0; $i < 3; $i++) {
            $reporter = User::factory()->create();
            ChatRoomUser::factory()->create([
                'room_id' => $this->room->id,
                'user_id' => $reporter->id,
                'is_online' => true,
            ]);
            ChatMessageReport::create([
                'message_id' => $this->message->id,
                'reported_by' => $reporter->id,
                'room_id' => $this->room->id,
                'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
                'reason' => "Report #{$i}",
                'status' => ChatMessageReport::STATUS_PENDING,
            ]);
        }

        Sanctum::actingAs($this->user);

        // This should trigger auto-moderation
        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'reason' => 'Final report that triggers auto-moderation',
        ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }

        $response->assertStatus(201);

        // Check that the message was deleted
        $this->assertSoftDeleted('chat_messages', [
            'id' => $this->message->id,
        ]);

        $this->assertEquals(4, ChatMessageReport::where('room_id', $this->room->id)
            ->where('status', ChatMessageReport::STATUS_RESOLVED)
            ->where('message_id', $this->message->id)
            ->count());

        $this->assertDatabaseHas('chat_moderation_actions', [
            'room_id' => $this->room->id,
            'moderator_id' => null,
            'target_user_id' => $this->admin->id,
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
            'reason' => 'Automatic deletion due to multiple reports',
        ]);
    }

    public function test_report_message_returns_500_when_transaction_throws_exception()
    {
        Sanctum::actingAs($this->user);

        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \Exception('transaction failed'));

        $response = $this->postJson("/api/chat/reports/rooms/{$this->room->id}/messages/{$this->message->id}", [
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Force exception path',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to report message')
            ->assertJsonPath('success', false);
    }

    public function test_review_report_returns_404_when_report_room_is_missing(): void
    {
        Sanctum::actingAs($this->admin);

        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->user->id,
            'room_id' => 999999,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'resolve',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Report or room not found');
    }

    public function test_review_report_skips_user_muted_action_when_target_not_in_room(): void
    {
        Sanctum::actingAs($this->admin);

        $outsider = User::factory()->create();
        $outsiderMessage = ChatMessage::create([
            'room_id' => $this->room->id,
            'user_id' => $outsider->id,
            'message' => 'outsider message',
            'message_type' => 'text',
        ]);

        $report = ChatMessageReport::create([
            'message_id' => $outsiderMessage->id,
            'reported_by' => $this->user->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        $response = $this->postJson("/api/chat/reports/{$report->id}/review", [
            'action' => 'resolve',
            'mute_user' => true,
            'mute_duration' => 30,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.action', 'resolve')
            ->assertJsonPath('data.actions_performed', []);
    }
}
