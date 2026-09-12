<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageReaction;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Services\Chat\ChatCacheService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRealtime;
use App\Services\Chat\ContentFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatMessageInteractionController extends Controller
{
    public function __construct(
        private readonly ChatMessageService $messages,
        private readonly ChatCacheService $cache,
    ) {}

    public function search(Request $request, int $roomId): JsonResponse
    {
        $this->requireMembership($roomId);
        $data = $request->validate(['q' => 'required|string|max:200', 'page' => 'nullable|integer|min:1']);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['q']))).'%';
        $results = ChatMessage::with(ChatMessage::DISPLAY_RELATIONS)
            ->forRoom($roomId)
            ->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(message) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('attachments', fn ($files) => $files->whereRaw("LOWER(original_name) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('user', fn ($users) => $users->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20);
        $results->setCollection($results->getCollection()->map(fn ($message) => (new ChatMessageResource($message))->resolve()));

        return response()->json($results);
    }

    public function update(Request $request, int $roomId, int $messageId): JsonResponse
    {
        $this->requireMembership($roomId, true);
        $message = ChatMessage::forRoom($roomId)->findOrFail($messageId);
        $this->authorize('update', $message);
        abort_unless($message->isTextMessage(), 422, 'System messages cannot be edited.');
        $data = $request->validate(['message' => 'present|nullable|string|max:1000']);
        $content = (string) $data['message'];
        $validation = trim($content) === '' && $message->attachments()->exists()
            ? ['valid' => true, 'sanitized_message' => '']
            : $this->messages->validateMessage($content);
        if (! $validation['valid']) {
            return $this->error($validation['errors'][0], [], 422);
        }
        $content = $validation['sanitized_message'];
        if ($content !== '') {
            $filtered = app(ContentFilterService::class)->processMessage($content, $request->user()->id, $roomId);
            if (! $filtered['allowed']) {
                return $this->error('Message blocked by content filter', [], 422);
            }
            $content = $filtered['filtered_message'];
        }
        $formatted = $this->messages->formatMessage($content, $this->messages->processMentions($content));
        $message = DB::transaction(function () use ($messageId, $roomId, $formatted) {
            $locked = ChatMessage::forRoom($roomId)->lockForUpdate()->findOrFail($messageId);
            if ($formatted !== $locked->message) {
                $locked->update(['message' => $formatted, 'edited_at' => now(), 'revision' => $locked->revision + 1]);
            }

            return $locked;
        });
        if ($message->wasChanged('message')) {
            $this->cache->invalidateMessageHistory($roomId);
            ChatRealtime::publish(new MessageUpdated($message));
        }

        return $this->success(['data' => new ChatMessageResource($message->load(ChatMessage::DISPLAY_RELATIONS))]);
    }

    public function reaction(Request $request, int $roomId, int $messageId): JsonResponse
    {
        $this->requireMembership($roomId, true);
        $message = ChatMessage::forRoom($roomId)->findOrFail($messageId);
        abort_unless($message->isTextMessage(), 422, 'System messages do not accept reactions.');
        $data = $request->validate(['emoji' => ['required', 'string', Rule::in(ChatMessageReaction::EMOJIS)]]);
        $attributes = ['message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $data['emoji']];
        $message = DB::transaction(function () use ($messageId, $roomId, $attributes, $request) {
            $locked = ChatMessage::forRoom($roomId)->lockForUpdate()->findOrFail($messageId);
            if ($request->isMethod('delete')) {
                ChatMessageReaction::where($attributes)->delete();
            } else {
                ChatMessageReaction::firstOrCreate($attributes);
            }
            $locked->increment('revision');

            return $locked->load(ChatMessage::DISPLAY_RELATIONS);
        });
        $this->cache->invalidateMessageHistory($roomId);
        ChatRealtime::publish(new MessageUpdated($message));

        return $this->success(['data' => new ChatMessageResource($message)]);
    }

    private function requireMembership(int $roomId, bool $write = false): void
    {
        $room = ChatRoom::active()->findOrFail($roomId);
        $member = ChatRoomUser::where('room_id', $roomId)->where('user_id', auth()->id())->first();
        abort_unless($member, 403, 'Join this room first.');
        $moderator = auth()->user()->canModerate($room);
        abort_if(! $moderator && $member->isBanned(), 403, 'You are banned from this room.');
        abort_if($write && ! $moderator && ! $member->canSendMessages(), 403, 'You cannot send messages in this room.');
    }
}
