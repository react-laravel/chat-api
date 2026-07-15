<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatIntegration extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'allowed_origins',
        'default_room_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'default_room_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
