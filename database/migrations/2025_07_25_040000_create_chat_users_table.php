<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary()->comment('中央 DogeOW 用户 ID');
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->boolean('is_admin')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_users');
    }
};
