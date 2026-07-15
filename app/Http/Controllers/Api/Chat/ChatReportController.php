<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ReportMessageRequest;
use App\Http\Requests\Chat\ReviewReportRequest;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReport;
use App\Models\Chat\ChatModerationAction;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use App\Services\Chat\ContentFilterService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatReportController extends Controller
{
    use ChatControllerHelpers;

    public function __construct(protected ContentFilterService $contentFilterService) {}

    /**
     * Check if user is an admin
     */
    private function ensureAdmin(User $user, string $message): ?JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return $this->error($message, [], 403);
        }

        return null;
    }

    /**
     * Report a message.
     */
    public function reportMessage(ReportMessageRequest $request, int $roomId, int $messageId): JsonResponse
    {
        $this->findActiveRoom($roomId);
        $message = ChatMessage::where('room_id', $roomId)->findOrFail($messageId);
        $reporter = $this->getModerator();

        // 确保举报者是房间成员
        $roomMembership = $this->ensureUserInRoom($roomId, $reporter->id, 'You must be a member of this room to report messages', 403);
        if ($roomMembership) {
            return $roomMembership;
        }

        if ($message->user_id === $reporter->id) {
            return $this->error('You cannot report your own message');
        }

        $existingReport = ChatMessageReport::where('message_id', $messageId)
            ->where('reported_by', $reporter->id)
            ->whereNotNull('message_id')
            ->first();

        if ($existingReport) {
            return $this->error('You have already reported this message', ['existing_report' => $existingReport]);
        }

        try {
            $report = DB::transaction(function () use ($messageId, $roomId, $reporter, $request, $message) {
                $report = ChatMessageReport::create([
                    'message_id' => $messageId,
                    'reported_by' => $reporter->id,
                    'room_id' => $roomId,
                    'report_type' => $request->report_type,
                    'reason' => $request->reason,
                    'status' => ChatMessageReport::STATUS_PENDING,
                    'metadata' => [
                        'reporter_ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'message_content_hash' => hash('sha256', $message->message),
                        'message_created_at' => $message->created_at->toISOString(),
                    ],
                ]);

                $report->load(['reporter:id,name,email', 'message.user:id,name,email']);

                $this->checkAutoModeration($messageId, $roomId);

                return $report;
            });

            return $this->success([
                'report' => $report,
                'report_id' => $report->id,
            ], 'Message reported successfully', 201);
        } catch (\Exception $e) {
            Log::error('Failed to report message', [
                'message_id' => $messageId,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to report message', [], 500);
        }
    }

    /**
     * Get reports for a room (moderators only).
     */
    public function getRoomReports(Request $request, int $roomId): JsonResponse
    {
        $room = $this->findActiveRoom($roomId);
        $user = $this->getModerator();

        $guard = $this->ensureCanModerate($user, $room, 'You are not authorized to view reports for this room');
        if ($guard) {
            return $guard;
        }

        $status = $request->input('status');
        $reportType = $request->input('report_type');

        $query = ChatMessageReport::forRoom($roomId)
            ->with([
                'reporter:id,name,email',
                'message.user:id,name,email',
                'reviewer:id,name,email',
            ])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($reportType) {
            $query->ofType($reportType);
        }

        $paged = $query->jsonPaginate();
        [$data, $meta] = $this->normalizePagination($paged);

        return $this->success([
            'reports' => $data,
            'pagination' => $meta,
        ]);
    }

    /**
     * Get all reports (admin only).
     */
    public function getAllReports(Request $request): JsonResponse
    {
        $user = $this->getModerator();

        $guard = $this->ensureAdmin($user, 'You are not authorized to view all reports');
        if ($guard) {
            return $guard;
        }

        $status = $request->input('status');
        $reportType = $request->input('report_type');
        $roomId = $request->input('room_id');

        $query = ChatMessageReport::with([
            'reporter:id,name,email',
            'message.user:id,name,email',
            'room:id,name',
            'reviewer:id,name,email',
        ])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($reportType) {
            $query->ofType($reportType);
        }

        if ($roomId) {
            $query->forRoom($roomId);
        }

        $paged = $query->jsonPaginate();
        [$data, $meta] = $this->normalizePagination($paged);

        return $this->success([
            'reports' => $data,
            'pagination' => $meta,
        ]);
    }

    /**
     * Review a report (moderators only).
     */
    public function reviewReport(ReviewReportRequest $request, int $reportId): JsonResponse
    {
        $report = ChatMessageReport::with(['room', 'message'])->findOrFail($reportId);
        $reviewer = $this->getModerator();

        $room = $report->room;
        if (! $room instanceof ChatRoom) {
            return $this->error('Report or room not found', [], 404);
        }

        $guard = $this->ensureCanModerate($reviewer, $room, 'You are not authorized to review this report');
        if ($guard) {
            return $guard;
        }

        try {
            $result = DB::transaction(function () use ($request, $report, $reviewer) {
                $action = $request->action;
                $notes = $request->notes;

                match ((string) $action) {
                    'resolve' => $report->markAsResolved($reviewer->id, $notes),
                    'dismiss' => $report->markAsDismissed($reviewer->id, $notes),
                    'escalate' => $report->markAsReviewed($reviewer->id, $notes),
                    default => null,
                };

                $actionsPerformed = [];

                if ($request->delete_message && $report->message) {
                    $report->message->delete();
                    $actionsPerformed[] = 'message_deleted';
                }

                if ($request->mute_user && $report->message) {
                    $roomUser = ChatRoomUser::where('room_id', $report->room_id)
                        ->where('user_id', $report->message->user_id)
                        ->first();

                    if ($roomUser) {
                        $muteDuration = $request->mute_duration ?? 60;
                        $roomUser->update([
                            'is_muted' => true,
                            'muted_until' => now()->addMinutes($muteDuration),
                            'muted_by' => $reviewer->id,
                        ]);
                        $actionsPerformed[] = 'user_muted';
                    }
                }

                return [
                    'report' => $report->fresh(['reporter:id,name,email', 'reviewer:id,name,email']),
                    'action' => $action,
                    'actions_performed' => $actionsPerformed,
                ];
            });

            return $this->success($result, 'Report reviewed successfully');
        } catch (\Exception $e) {

            Log::error('Failed to review report', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to review report', [], 500);
        }
    }

    /**
     * Get report statistics.
     */
    public function getReportStats(Request $request): JsonResponse
    {
        $user = $this->getModerator();
        $roomId = $request->input('room_id');
        $days = (int) $request->input('days', 7);

        if ($roomId) {
            $room = $this->findActiveRoom($roomId);
            $guard = $this->ensureCanModerate($user, $room, 'You are not authorized to view stats for this room');
            if ($guard) {
                return $guard;
            }
        } else {
            $guard = $this->ensureAdmin($user, 'You are not authorized to view global report stats');
            if ($guard) {
                return $guard;
            }
        }

        $baseQuery = fn () => ChatMessageReport::query()
            ->where('chat_message_reports.created_at', '>=', now()->subDays($days))
            ->when($roomId, fn ($q) => $q->where('chat_message_reports.room_id', $roomId));

        // 状态统计(单次查询)
        $statusCounts = $baseQuery()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalReports = $statusCounts->sum();

        // 举报类型统计
        $reportTypes = $baseQuery()
            ->selectRaw('report_type, count(*) as count')
            ->groupBy('report_type')
            ->pluck('count', 'report_type')
            ->toArray();

        // 严重程度统计(通过 report_type 映射)
        $severityMap = [
            ChatMessageReport::TYPE_HATE_SPEECH => 'high',
            ChatMessageReport::TYPE_VIOLENCE => 'high',
            ChatMessageReport::TYPE_HARASSMENT => 'high',
            ChatMessageReport::TYPE_INAPPROPRIATE_CONTENT => 'medium',
            ChatMessageReport::TYPE_SEXUAL_CONTENT => 'medium',
            ChatMessageReport::TYPE_MISINFORMATION => 'medium',
            ChatMessageReport::TYPE_SPAM => 'low',
            ChatMessageReport::TYPE_OTHER => 'low',
        ];

        $severityBreakdown = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($reportTypes as $type => $count) {
            $severity = $severityMap[$type] ?? 'low';
            $severityBreakdown[$severity] += $count;
        }

        // Top reporters
        $topReporters = $baseQuery()
            ->select('reported_by', DB::raw('count(*) as report_count'))
            ->groupBy('reported_by')
            ->orderByDesc('report_count')
            ->limit(5)
            ->with('reporter:id,name')
            ->get()
            ->map(fn (ChatMessageReport $r) => [
                'user_id' => $r->reported_by,
                'user_name' => $r->reporter->name ?? 'Unknown',
                'report_count' => (int) $r->getAttribute('report_count'),
            ]);

        // Top reported users
        $topReportedUsers = $baseQuery()
            ->join('chat_messages', 'chat_message_reports.message_id', '=', 'chat_messages.id')
            ->select('chat_messages.user_id', DB::raw('count(*) as report_count'))
            ->groupBy('chat_messages.user_id')
            ->orderByDesc('report_count')
            ->limit(5)
            ->get()
            ->map(fn (ChatMessageReport $r) => [
                'user_id' => $r->getAttribute('user_id'),
                'user_name' => User::find($r->getAttribute('user_id'))->name ?? 'Unknown',
                'report_count' => (int) $r->getAttribute('report_count'),
            ]);

        $filterStats = $this->contentFilterService->getFilterStats($roomId, $days);

        return $this->success([
            'total_reports' => $totalReports,
            'pending_reports' => $statusCounts->get(ChatMessageReport::STATUS_PENDING, 0),
            'resolved_reports' => $statusCounts->get(ChatMessageReport::STATUS_RESOLVED, 0),
            'dismissed_reports' => $statusCounts->get(ChatMessageReport::STATUS_DISMISSED, 0),
            'report_types' => $reportTypes,
            'severity_breakdown' => $severityBreakdown,
            'top_reporters' => $topReporters,
            'top_reported_users' => $topReportedUsers,
            'period_days' => $days,
            'content_filter' => $filterStats,
        ]);
    }

    /**
     * 根据举报数量自动审核消息
     * 只计算来自房间成员的有效举报，防止恶意刷举报
     */
    private function checkAutoModeration(int $messageId, int $roomId): void
    {
        $reportCount = ChatMessageReport::where('message_id', $messageId)
            ->where('status', ChatMessageReport::STATUS_PENDING)
            ->whereRaw('exists (select 1 from `chat_room_users` where `chat_room_users`.`room_id` = ? and `chat_room_users`.`user_id` = `chat_message_reports`.`reported_by`)', [$roomId])
            ->count();

        if ($reportCount < 3) {
            return;
        }

        $message = ChatMessage::find($messageId);
        if (! $message) {
            return;
        }

        ChatModerationAction::create([
            'room_id' => $roomId,
            'moderator_id' => null, // Automated action - no human moderator
            'target_user_id' => $message->user_id,
            'message_id' => $messageId,
            'action_type' => ChatModerationAction::ACTION_DELETE_MESSAGE,
            'reason' => 'Automatic deletion due to multiple reports',
            'metadata' => [
                'report_count' => $reportCount,
                'auto_action' => true,
                'auto_action_reason' => 'Message received 3+ reports',
                'original_message' => $message->message,
            ],
        ]);

        // 批量更新所有待处理举报
        ChatMessageReport::where('message_id', $messageId)
            ->where('status', ChatMessageReport::STATUS_PENDING)
            ->update([
                'status' => ChatMessageReport::STATUS_RESOLVED,
                'reviewed_by' => null, // Automated action - no human reviewer
                'reviewed_at' => now(),
                'review_notes' => 'Auto-resolved due to message deletion from multiple reports',
            ]);

        $message->delete();
    }

    /**
     * Normalize JsonApiPaginate output into a stable data/meta pair.
     *
     * @return array{0: mixed, 1: array}
     */
    private function normalizePagination(mixed $paged): array
    {
        if (is_array($paged) && isset($paged['data'])) {
            return [$paged['data'], $paged['meta'] ?? []];
        }

        if ($paged instanceof LengthAwarePaginator) {
            return [
                $paged->items(),
                [
                    'current_page' => $paged->currentPage(),
                    'per_page' => $paged->perPage(),
                    'total' => $paged->total(),
                ],
            ];
        }

        if (is_object($paged) && method_exists($paged, 'toArray')) {
            $arr = $paged->toArray();

            return [
                $arr['data'] ?? (method_exists($paged, 'items') ? $paged->items() : []),
                $arr['meta'] ?? (
                    method_exists($paged, 'currentPage')
                        ? [
                            'current_page' => $paged->currentPage(),
                            'per_page' => $paged->perPage(),
                            'total' => $paged->total(),
                        ]
                        : []
                ),
            ];
        }

        return [is_object($paged) && method_exists($paged, 'items') ? $paged->items() : [], []];
    }
}
