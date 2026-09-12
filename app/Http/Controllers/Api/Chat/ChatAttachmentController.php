<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatAttachment;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    public function drafts(int $roomId): JsonResponse
    {
        $this->member($roomId);
        $attachments = ChatAttachment::where('room_id', $roomId)->where('user_id', auth()->id())
            ->whereNull('message_id')->where('created_at', '>=', now()->subDay())->orderBy('id')->get();

        return $this->success(['attachments' => $attachments->map->payload()->all()]);
    }

    public function store(Request $request, int $roomId): JsonResponse
    {
        $this->member($roomId, true);
        $request->validate(['upload_id' => 'nullable|uuid', 'file' => ['required', 'file', 'max:'.ChatAttachment::MAX_FILE_KB, 'mimes:'.implode(',', ChatAttachment::EXTENSIONS)]]);
        if ($request->filled('upload_id')) {
            $existing = ChatAttachment::where('user_id', auth()->id())->where('client_id', $request->input('upload_id'))->first();
            if ($existing) {
                abort_unless($existing->room_id === $roomId && $existing->message_id === null && $existing->created_at->gte(now()->subDay()), 409, '此附件已经发送，请重新选择文件。');

                return $this->success(['attachment' => $existing->payload()]);
            }
        }
        abort_if(ChatAttachment::where('user_id', auth()->id())->whereNull('message_id')->count() >= 20, 422, '请先发送或移除已有的待发送附件。');
        $file = $request->file('file');
        $path = $file->store('chat/attachments/'.$roomId, 'local');
        abort_unless($path, 500, '附件保存失败，请重试。');
        try {
            $name = mb_substr(str_replace(["\r", "\n", "\0"], '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 180);
            $attachment = ChatAttachment::create(['room_id' => $roomId, 'user_id' => $request->user()->id, 'path' => $path,
                'client_id' => $request->input('upload_id'), 'original_name' => $name, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            if ($request->filled('upload_id')) {
                $existing = ChatAttachment::where('user_id', auth()->id())->where('room_id', $roomId)->where('client_id', $request->input('upload_id'))->whereNull('message_id')->first();
                if ($existing) {
                    return $this->success(['attachment' => $existing->payload()]);
                }
            }
            throw $error;
        }

        return $this->success(['attachment' => $attachment->payload()], 'Attachment uploaded', 201);
    }

    public function show(int $roomId, int $attachmentId): StreamedResponse
    {
        $this->member($roomId);
        $attachment = ChatAttachment::where('room_id', $roomId)->findOrFail($attachmentId);
        if ($attachment->message_id === null) {
            abort_unless($attachment->user_id === auth()->id() && $attachment->created_at->gte(now()->subDay()), 403);
        } else {
            abort_unless($attachment->message()->exists(), 404);
        }
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return str_starts_with($attachment->mime_type, 'image/')
            ? Storage::disk('local')->response($attachment->path, $attachment->original_name, $headers)
            : Storage::disk('local')->download($attachment->path, $attachment->original_name, $headers);
    }

    public function destroy(int $roomId, int $attachmentId): JsonResponse
    {
        $this->member($roomId);
        $attachment = ChatAttachment::where('room_id', $roomId)->where('user_id', auth()->id())->whereNull('message_id')->findOrFail($attachmentId);
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return $this->success([], 'Attachment removed');
    }

    private function member(int $roomId, bool $write = false): void
    {
        $room = ChatRoom::active()->findOrFail($roomId);
        $member = ChatRoomUser::where('room_id', $roomId)->where('user_id', auth()->id())->first();
        abort_unless($member, 403, 'Join this room first.');
        abort_if($member->isBanned() && ! auth()->user()->canModerate($room), 403, 'You are banned from this room.');
        abort_if($write && ! $member->canSendMessages() && ! auth()->user()->canModerate($room), 403, 'You cannot send messages in this room.');
    }
}
