<?php

use App\Http\Controllers\Api\Chat\ChatMessageController;
use App\Http\Controllers\Api\Chat\ChatModerationController;
use App\Http\Controllers\Api\Chat\ChatModerationLogController;
use App\Http\Controllers\Api\Chat\ChatPresenceController;
use App\Http\Controllers\Api\Chat\ChatReportController;
use App\Http\Controllers\Api\Chat\ChatRoomController;
use App\Http\Controllers\Api\Chat\ChatUserModerationController;
use Illuminate\Support\Facades\Route;

Route::prefix('chat')->group(function () {
    // Room management endpoints
    Route::get('/rooms', [ChatRoomController::class, 'index']);
    Route::post('/rooms', [ChatRoomController::class, 'store'])->middleware('idempotency');
    Route::match(['put', 'patch'], '/rooms/{roomId}', [ChatRoomController::class, 'update']);
    Route::post('/rooms/{roomId}/join', [ChatRoomController::class, 'join'])->middleware('idempotency');
    Route::post('/rooms/{roomId}/leave', [ChatRoomController::class, 'leave'])->middleware('idempotency');
    Route::delete('/rooms/{roomId}', [ChatRoomController::class, 'destroy']);

    // Message handling endpoints
    Route::get('/rooms/{roomId}/messages', [ChatMessageController::class, 'index']);
    Route::post('/rooms/{roomId}/messages', [ChatMessageController::class, 'store'])->middleware('idempotency', 'throttle:chat-message');
    Route::delete('/rooms/{roomId}/messages/{messageId}', [ChatMessageController::class, 'destroy']);

    // User presence management endpoints
    Route::get('/rooms/{roomId}/users', [ChatPresenceController::class, 'users']);
    Route::post('/rooms/{roomId}/status', [ChatPresenceController::class, 'heartbeat']);
    Route::get('/rooms/{roomId}/my-status', [ChatPresenceController::class, 'status']);
    Route::post('/cleanup-disconnected', [ChatPresenceController::class, 'cleanup']);

    // Moderation endpoints (admin/moderator only)
    Route::prefix('moderation')->group(function () {
        // Message moderation
        Route::delete('/rooms/{roomId}/messages/{messageId}', [ChatModerationController::class, 'deleteMessage']);

        // User moderation actions
        Route::post('/rooms/{roomId}/users/{userId}/mute', [ChatModerationController::class, 'muteUser']);
        Route::post('/rooms/{roomId}/users/{userId}/unmute', [ChatModerationController::class, 'unmuteUser']);
        Route::post('/rooms/{roomId}/users/{userId}/ban', [ChatModerationController::class, 'banUser']);
        Route::post('/rooms/{roomId}/users/{userId}/unban', [ChatModerationController::class, 'unbanUser']);

        // Moderation logs
        Route::get('/rooms/{roomId}/actions', [ChatModerationLogController::class, 'getModerationActions']);

        // User moderation status
        Route::get('/rooms/{roomId}/users/{userId}/status', [ChatUserModerationController::class, 'getUserModerationStatus']);
    });

    // Reporting endpoints
    Route::prefix('reports')->group(function () {
        // Report messages
        Route::post('/rooms/{roomId}/messages/{messageId}', [ChatReportController::class, 'reportMessage']);

        // View reports (moderators only)
        Route::get('/rooms/{roomId}', [ChatReportController::class, 'getRoomReports']);
        Route::get('/', [ChatReportController::class, 'getAllReports']);

        // Review reports (moderators only)
        Route::post('/{reportId}/review', [ChatReportController::class, 'reviewReport']);

        // Report statistics
        Route::get('/stats', [ChatReportController::class, 'getReportStats']);
    });
});
