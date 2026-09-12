<?php

namespace App\Http\Resources\Chat;

use App\Models\Chat\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChatMessage
 */
class ChatMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var ChatMessage $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'room_id' => $resource->room_id,
            'user_id' => $resource->user_id,
            'message' => $resource->message,
            'message_type' => $resource->message_type,
            'created_at' => $resource->created_at?->toISOString(),
            'updated_at' => $resource->updated_at?->toISOString(),
            'edited_at' => $resource->edited_at?->toISOString(),
            'revision' => (int) $resource->revision,
            'reply_to_id' => $resource->reply_to_id,
            'reply_to' => $this->whenLoaded('replyTo', function () use ($resource) {
                $reply = $resource->replyTo;
                if (! $reply || $reply->room_id !== $resource->room_id) {
                    return null;
                }

                return [
                    'id' => $reply->id,
                    'deleted' => $reply->trashed(),
                    'attachment_count' => $reply->trashed() ? 0 : $reply->attachments->count(),
                    'message' => $reply->trashed() ? null : $reply->message,
                    'user' => $reply->trashed() ? null : ['id' => $reply->user->id, 'name' => $reply->user->name],
                ];
            }, null),
            'attachments' => $this->whenLoaded('attachments', fn () => $resource->attachments->map->payload()->all(), []),
            'reactions' => $this->whenLoaded('reactions', fn () => $resource->reactions
                ->groupBy('emoji')->map(fn ($items, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $items->count(),
                    'user_ids' => $items->pluck('user_id')->values()->all(),
                ])->values()->all(), []),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $resource->user->id,
                'name' => $resource->user->name,
                'email' => $resource->user->email,
            ]),
        ];
    }
}
