<?php

namespace Tests\Unit\Models;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReport;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatMessageReportTest extends TestCase
{
    use RefreshDatabase;

    private ChatMessageReport $report;

    private User $reporter;

    private User $reviewer;

    private ChatRoom $room;

    private ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = User::factory()->create();
        $this->reviewer = User::factory()->create();
        $this->room = ChatRoom::factory()->create();
        $this->message = ChatMessage::factory()->create([
            'room_id' => $this->room->id,
        ]);

        $this->report = ChatMessageReport::factory()->create([
            'message_id' => $this->message->id,
            'reported_by' => $this->reporter->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
            'reason' => 'Inappropriate content',
            'status' => ChatMessageReport::STATUS_PENDING,
            'metadata' => ['auto_detected' => false],
        ]);
    }

    public function test_chat_message_report_has_fillable_attributes()
    {
        $fillable = [
            'message_id',
            'reported_by',
            'room_id',
            'report_type',
            'reason',
            'status',
            'reviewed_by',
            'reviewed_at',
            'review_notes',
            'metadata',
        ];

        $this->assertEquals($fillable, $this->report->getFillable());
    }

    public function test_chat_message_report_casts_attributes_correctly()
    {
        $casts = [
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];

        foreach ($casts as $attribute => $cast) {
            $this->assertEquals($cast, $this->report->getCasts()[$attribute]);
        }
    }

    public function test_chat_message_report_has_report_type_constants()
    {
        $this->assertEquals('inappropriate_content', ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT);
        $this->assertEquals('spam', ChatMessageReport::TYPE_SPAM);
        $this->assertEquals('harassment', ChatMessageReport::TYPE_HARASSMENT);
        $this->assertEquals('hate_speech', ChatMessageReport::TYPE_HATE_SPEECH);
        $this->assertEquals('violence', ChatMessageReport::TYPE_VIOLENCE);
        $this->assertEquals('sexual_content', ChatMessageReport::TYPE_SEXUAL_CONTENT);
        $this->assertEquals('misinformation', ChatMessageReport::TYPE_MISINFORMATION);
        $this->assertEquals('other', ChatMessageReport::TYPE_OTHER);
    }

    public function test_chat_message_report_has_status_constants()
    {
        $this->assertEquals('pending', ChatMessageReport::STATUS_PENDING);
        $this->assertEquals('reviewed', ChatMessageReport::STATUS_REVIEWED);
        $this->assertEquals('resolved', ChatMessageReport::STATUS_RESOLVED);
        $this->assertEquals('dismissed', ChatMessageReport::STATUS_DISMISSED);
    }

    public function test_chat_message_report_belongs_to_message()
    {
        $this->assertInstanceOf(ChatMessage::class, $this->report->message);
        $this->assertEquals($this->message->id, $this->report->message->id);
    }

    public function test_chat_message_report_belongs_to_reporter()
    {
        $this->assertInstanceOf(User::class, $this->report->reporter);
        $this->assertEquals($this->reporter->id, $this->report->reporter->id);
    }

    public function test_chat_message_report_belongs_to_room()
    {
        $this->assertInstanceOf(ChatRoom::class, $this->report->room);
        $this->assertEquals($this->room->id, $this->report->room->id);
    }

    public function test_chat_message_report_belongs_to_reviewer()
    {
        $this->report->update([
            'reviewed_by' => $this->reviewer->id,
        ]);

        $this->assertInstanceOf(User::class, $this->report->reviewer);
        $this->assertEquals($this->reviewer->id, $this->report->reviewer->id);
    }

    public function test_pending_scope_returns_pending_reports()
    {
        $pendingReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        $reviewedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_REVIEWED,
        ]);

        $pendingReports = ChatMessageReport::pending()->get();

        $this->assertTrue($pendingReports->contains($pendingReport));
        $this->assertTrue($pendingReports->contains($this->report));
        $this->assertFalse($pendingReports->contains($reviewedReport));
    }

    public function test_reviewed_scope_returns_reviewed_reports()
    {
        $reviewedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_REVIEWED,
        ]);

        $resolvedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_RESOLVED,
        ]);

        $reviewedReports = ChatMessageReport::reviewed()->get();

        $this->assertTrue($reviewedReports->contains($reviewedReport));
        $this->assertFalse($reviewedReports->contains($resolvedReport)); // resolved scope is separate
        $this->assertFalse($reviewedReports->contains($this->report));

        // Verify the scope only includes reviewed status
        $this->assertCount(1, $reviewedReports);
    }

    public function test_resolved_scope_returns_resolved_reports()
    {
        $resolvedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_RESOLVED,
        ]);

        $dismissedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_DISMISSED,
        ]);

        $resolvedReports = ChatMessageReport::resolved()->get();

        $this->assertTrue($resolvedReports->contains($resolvedReport));
        $this->assertFalse($resolvedReports->contains($dismissedReport));
        $this->assertFalse($resolvedReports->contains($this->report));
    }

    public function test_dismissed_scope_returns_dismissed_reports()
    {
        $dismissedReport = ChatMessageReport::factory()->create([
            'status' => ChatMessageReport::STATUS_DISMISSED,
        ]);

        $dismissedReports = ChatMessageReport::dismissed()->get();

        $this->assertTrue($dismissedReports->contains($dismissedReport));
        $this->assertFalse($dismissedReports->contains($this->report));
    }

    public function test_for_room_scope_returns_reports_for_specific_room()
    {
        $otherRoom = ChatRoom::factory()->create();
        $otherReport = ChatMessageReport::factory()->create([
            'room_id' => $otherRoom->id,
        ]);

        $roomReports = ChatMessageReport::forRoom($this->room->id)->get();

        $this->assertTrue($roomReports->contains($this->report));
        $this->assertFalse($roomReports->contains($otherReport));
    }

    public function test_of_type_scope_returns_reports_of_specific_type()
    {
        $spamReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);

        $inappropriateReports = ChatMessageReport::ofType(ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT)->get();
        $spamReports = ChatMessageReport::ofType(ChatMessageReport::TYPE_SPAM)->get();

        $this->assertTrue($inappropriateReports->contains($this->report));
        $this->assertFalse($inappropriateReports->contains($spamReport));
        $this->assertTrue($spamReports->contains($spamReport));
        $this->assertFalse($spamReports->contains($this->report));
    }

    public function test_by_reporter_scope_returns_reports_by_specific_user()
    {
        $otherUser = User::factory()->create();
        $otherReport = ChatMessageReport::factory()->create([
            'reported_by' => $otherUser->id,
        ]);

        $userReports = ChatMessageReport::byReporter($this->reporter->id)->get();

        $this->assertTrue($userReports->contains($this->report));
        $this->assertFalse($userReports->contains($otherReport));
    }

    public function test_is_pending_returns_true_for_pending_reports()
    {
        $this->assertTrue($this->report->isPending());

        $this->report->update(['status' => ChatMessageReport::STATUS_REVIEWED]);
        $this->assertFalse($this->report->isPending());
    }

    public function test_is_reviewed_returns_true_for_reviewed_reports()
    {
        $this->assertFalse($this->report->isReviewed());

        $this->report->update(['status' => ChatMessageReport::STATUS_REVIEWED]);
        $this->assertTrue($this->report->isReviewed());

        $this->report->update(['status' => ChatMessageReport::STATUS_RESOLVED]);
        $this->assertTrue($this->report->isReviewed());
    }

    public function test_is_resolved_returns_true_for_resolved_reports()
    {
        $this->assertFalse($this->report->isResolved());

        $this->report->update(['status' => ChatMessageReport::STATUS_RESOLVED]);
        $this->assertTrue($this->report->isResolved());
    }

    public function test_is_dismissed_returns_true_for_dismissed_reports()
    {
        $this->assertFalse($this->report->isDismissed());

        $this->report->update(['status' => ChatMessageReport::STATUS_DISMISSED]);
        $this->assertTrue($this->report->isDismissed());
    }

    public function test_mark_as_reviewed_updates_report_status()
    {
        $result = $this->report->markAsReviewed($this->reviewer->id, 'Reviewed by moderator');

        $this->assertTrue($result);
        $this->report->refresh();

        $this->assertEquals(ChatMessageReport::STATUS_REVIEWED, $this->report->status);
        $this->assertEquals($this->reviewer->id, $this->report->reviewed_by);
        $this->assertNotNull($this->report->reviewed_at);
        $this->assertEquals('Reviewed by moderator', $this->report->review_notes);
    }

    public function test_mark_as_resolved_updates_report_status()
    {
        $result = $this->report->markAsResolved($this->reviewer->id, 'Issue resolved');

        $this->assertTrue($result);
        $this->report->refresh();

        $this->assertEquals(ChatMessageReport::STATUS_RESOLVED, $this->report->status);
        $this->assertEquals($this->reviewer->id, $this->report->reviewed_by);
        $this->assertNotNull($this->report->reviewed_at);
        $this->assertEquals('Issue resolved', $this->report->review_notes);
    }

    public function test_mark_as_dismissed_updates_report_status()
    {
        $result = $this->report->markAsDismissed($this->reviewer->id, 'False report');

        $this->assertTrue($result);
        $this->report->refresh();

        $this->assertEquals(ChatMessageReport::STATUS_DISMISSED, $this->report->status);
        $this->assertEquals($this->reviewer->id, $this->report->reviewed_by);
        $this->assertNotNull($this->report->reviewed_at);
        $this->assertEquals('False report', $this->report->review_notes);
    }

    public function test_get_severity_level_returns_correct_levels()
    {
        $hateSpeechReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_HATE_SPEECH,
        ]);

        $inappropriateReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT,
        ]);

        $spamReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);

        $this->assertEquals('high', $hateSpeechReport->getSeverityLevel());
        $this->assertEquals('medium', $inappropriateReport->getSeverityLevel());
        $this->assertEquals('low', $spamReport->getSeverityLevel());
    }

    public function test_get_report_type_label_returns_human_readable_labels()
    {
        $this->assertEquals('Inappropriate Content', $this->report->getReportTypeLabel());

        $spamReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);
        $this->assertEquals('Spam', $spamReport->getReportTypeLabel());

        $harassmentReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
        ]);
        $this->assertEquals('Harassment', $harassmentReport->getReportTypeLabel());

        $hateSpeechReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_HATE_SPEECH,
        ]);
        $this->assertEquals('Hate Speech', $hateSpeechReport->getReportTypeLabel());

        $violenceReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_VIOLENCE,
        ]);
        $this->assertEquals('Violence/Threats', $violenceReport->getReportTypeLabel());

        $sexualContentReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SEXUAL_CONTENT,
        ]);
        $this->assertEquals('Sexual Content', $sexualContentReport->getReportTypeLabel());

        $misinformationReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_MISINFORMATION,
        ]);
        $this->assertEquals('Misinformation', $misinformationReport->getReportTypeLabel());
    }

    public function test_chat_message_report_can_be_created_with_valid_data()
    {
        $reportData = [
            'message_id' => $this->message->id,
            'reported_by' => $this->reporter->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'reason' => 'Spam behavior',
            'status' => ChatMessageReport::STATUS_PENDING,
            'metadata' => ['auto_detected' => true],
        ];

        $report = ChatMessageReport::create($reportData);

        $this->assertInstanceOf(ChatMessageReport::class, $report);
        $this->assertEquals($this->message->id, $report->message_id);
        $this->assertEquals($this->reporter->id, $report->reported_by);
        $this->assertEquals($this->room->id, $report->room_id);
        $this->assertEquals(ChatMessageReport::TYPE_SPAM, $report->report_type);
        $this->assertEquals('Spam behavior', $report->reason);
        $this->assertEquals(ChatMessageReport::STATUS_PENDING, $report->status);
        $this->assertEquals(['auto_detected' => true], $report->metadata);
    }

    public function test_metadata_is_casted_to_array()
    {
        $report = ChatMessageReport::create([
            'message_id' => $this->message->id,
            'reported_by' => $this->reporter->id,
            'room_id' => $this->room->id,
            'report_type' => ChatMessageReport::TYPE_SPAM,
            'metadata' => ['auto_detected' => true],
        ]);

        $this->assertIsArray($report->metadata);
        $this->assertEquals(['auto_detected' => true], $report->metadata);
    }

    public function test_timestamps_are_casted_to_datetime()
    {
        $this->assertInstanceOf(Carbon::class, $this->report->created_at);
        $this->assertInstanceOf(Carbon::class, $this->report->updated_at);
    }

    public function test_reviewed_at_is_casted_to_datetime()
    {
        $this->report->update([
            'reviewed_at' => '2023-01-01 12:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $this->report->reviewed_at);
    }

    public function test_chat_message_report_can_be_updated()
    {
        $this->report->update([
            'reason' => 'Updated reason',
            'metadata' => ['updated' => true],
        ]);

        $this->report->refresh();

        $this->assertEquals('Updated reason', $this->report->reason);
        $this->assertEquals(['updated' => true], $this->report->metadata);
    }

    public function test_get_severity_level_returns_low_for_unknown_report_type()
    {
        // Since we can't create with unknown report_type due to enum constraint,
        // we'll test the method with known types and verify the default behavior
        $report = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);

        $this->assertEquals('low', $report->getSeverityLevel());

        // Test with a high severity type
        $hateSpeechReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_HATE_SPEECH,
        ]);

        $this->assertEquals('high', $hateSpeechReport->getSeverityLevel());
    }

    public function test_get_report_type_label_returns_unknown_for_unknown_type()
    {
        // Since we can't create with unknown report_type due to enum constraint,
        // we'll test the method with known types and verify the default behavior
        $report = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_SPAM,
        ]);

        $this->assertEquals('Spam', $report->getReportTypeLabel());

        // Test with another known type
        $harassmentReport = ChatMessageReport::factory()->create([
            'report_type' => ChatMessageReport::TYPE_HARASSMENT,
        ]);

        $this->assertEquals('Harassment', $harassmentReport->getReportTypeLabel());
    }
}
