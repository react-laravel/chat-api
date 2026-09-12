<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomInvite;
use App\Models\Chat\ChatRoomUser;
use App\Services\Chat\ChatCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatRoomInviteController extends Controller
{
    public function index(int $roomId): JsonResponse
    {
        $this->managedRoom($roomId);
        $invites = ChatRoomInvite::where('room_id', $roomId)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->get();

        return $this->success(['invites' => $invites]);
    }

    public function store(Request $request, int $roomId): JsonResponse
    {
        $this->managedRoom($roomId);
        $data = $request->validate(['expires_in_hours' => 'nullable|integer|min:1|max:168', 'max_uses' => 'nullable|integer|min:1|max:1000']);
        $token = Str::random(64);
        $invite = ChatRoomInvite::create([
            'room_id' => $roomId, 'created_by' => $request->user()->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours($data['expires_in_hours'] ?? 168),
            'max_uses' => $data['max_uses'] ?? null,
        ]);

        return $this->success(['invite' => $invite, 'token' => $token], 'Invite created', 201);
    }

    public function destroy(int $roomId, int $inviteId): JsonResponse
    {
        $this->managedRoom($roomId);
        ChatRoomInvite::where('room_id', $roomId)->findOrFail($inviteId)->update(['revoked_at' => now()]);

        return $this->success([], 'Invite revoked');
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $room = DB::transaction(function () use ($data, $request) {
            $invite = ChatRoomInvite::where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->firstOrFail();
            abort_if($invite->revoked_at || $invite->expires_at->isPast(), 410, '邀请已失效，请向房间管理员获取新链接。');
            $room = ChatRoom::active()->findOrFail($invite->room_id);
            $member = ChatRoomUser::where('room_id', $room->id)->where('user_id', $request->user()->id)->first();
            abort_if($member?->isBanned() && ! $request->user()->canModerate($room), 403, '你已被此房间封禁。');
            if ($member) {
                return $room;
            }
            abort_if($invite->max_uses !== null && $invite->uses_count >= $invite->max_uses, 410, '邀请名额已用完。');
            $member = ChatRoomUser::firstOrCreate(['room_id' => $room->id, 'user_id' => $request->user()->id], ['joined_at' => now(), 'is_online' => false, 'last_read_message_id' => ChatMessage::forRoom($room->id)->max('id'), 'last_read_at' => now()]);
            if ($member->wasRecentlyCreated) {
                $invite->increment('uses_count');
            }

            return $room;
        });
        app(ChatCacheService::class)->invalidateRoomList();

        return $this->success(['room' => $room], 'Invitation accepted');
    }

    private function managedRoom(int $roomId): ChatRoom
    {
        $room = ChatRoom::active()->findOrFail($roomId);
        $this->authorize('update', $room);

        return $room;
    }
}
