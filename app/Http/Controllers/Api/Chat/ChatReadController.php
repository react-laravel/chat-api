<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\RoomRead;
use App\Http\Controllers\Controller;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Services\Chat\ChatRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatReadController extends Controller
{
    public function update(Request $request, int $roomId): JsonResponse
    {
        $room = ChatRoom::active()->findOrFail($roomId);
        $data = $request->validate(['message_id' => ['required', 'integer', Rule::exists('chat_messages', 'id')->where('room_id', $roomId)]]);
        $member = DB::transaction(function () use ($request, $room, $data) {
            $member = ChatRoomUser::where('room_id', $room->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($member, 403, 'Join this room first.');
            abort_if($member->isBanned() && ! $request->user()->canModerate($room), 403, 'You are banned from this room.');
            if ($data['message_id'] > ($member->last_read_message_id ?? 0)) {
                $member->update(['last_read_message_id' => $data['message_id'], 'last_read_at' => now()]);
            }

            return $member;
        });
        $unread = ChatMessage::forRoom($roomId)->textMessages()->where('id', '>', $member->last_read_message_id)
            ->where('user_id', '!=', $request->user()->id)->count();
        if ($member->wasChanged('last_read_message_id')) {
            ChatRealtime::publish(new RoomRead($request->user()->id, $roomId, $member->last_read_message_id, $unread));
        }

        return $this->success(['room_id' => $roomId, 'last_read_message_id' => $member->last_read_message_id, 'unread_count' => $unread]);
    }
}
