<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name');
            $table->json('allowed_origins');
            $table->unsignedBigInteger('default_room_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('chat_integrations')->insert([
            [
                'client_id' => 'chat',
                'name' => 'DogeOW Chat',
                'allowed_origins' => json_encode(['https://chat.dogeow.com', 'http://localhost:3013', 'http://127.0.0.1:3013']),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => 'dogeow',
                'name' => 'DogeOW',
                'allowed_origins' => json_encode(['https://dogeow.com', 'https://next.dogeow.com']),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => 'game',
                'name' => 'DogeOW Game',
                'allowed_origins' => json_encode(['https://game.dogeow.com']),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => 'rpg',
                'name' => 'DogeOW RPG',
                'allowed_origins' => json_encode(['https://rpg.dogeow.com']),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => 'repo-watch',
                'name' => 'Repo Watch',
                'allowed_origins' => json_encode(['https://repo-watch.dogeow.com']),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_integrations');
    }
};
