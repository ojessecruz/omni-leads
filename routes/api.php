<?php

use App\Http\Controllers\Api\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('conversations/awaiting', [ConversationController::class, 'awaiting'])
        ->middleware('ability:conversations:read')
        ->name('api.conversations.awaiting');

    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])
        ->middleware('ability:conversations:read')
        ->whereNumber('conversation')
        ->name('api.conversations.messages');

    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])
        ->middleware('ability:conversations:reply')
        ->whereNumber('conversation')
        ->name('api.conversations.reply');
});
