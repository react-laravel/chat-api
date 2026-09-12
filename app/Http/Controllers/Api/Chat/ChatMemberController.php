<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMemberController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $room = ChatRoom::active()->findOrFail($roomId);
        $this->authorize('update', $room);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = ChatRoomUser::where('room_id', $roomId)->with('user:id,name,email,is_admin');
        if (! empty($data['q'])) {
            $query->whereHas('user', fn ($users) => $users->where('name', 'like', '%'.$data['q'].'%'));
        }
        $members = $query->orderByDesc('is_banned')->orderByDesc('is_muted')->orderBy('id')->paginate(30);
        $members->setCollection($members->getCollection()->map(fn ($member) => [
            'id' => $member->user_id, 'name' => $member->user->name,
            'role' => $member->user->is_admin ? 'admin' : ($room->created_by === $member->user_id ? 'moderator' : 'user'),
            'is_online' => $member->is_online, 'joined_at' => $member->joined_at?->toISOString(),
            'is_muted' => $member->isMuted(), 'muted_until' => $member->muted_until?->toISOString(),
            'is_banned' => $member->isBanned(), 'banned_until' => $member->banned_until?->toISOString(),
        ]));

        return response()->json($members);
    }
}
