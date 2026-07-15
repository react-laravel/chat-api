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
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $resource->user->id,
                'name' => $resource->user->name,
                'email' => $resource->user->email,
            ]),
        ];
    }
}
