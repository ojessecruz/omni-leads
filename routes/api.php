<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\LeadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('conversations/awaiting', [ConversationController::class, 'awaiting'])
        ->middleware('ability:conversations:read')
        ->name('api.conversations.awaiting');

    Route::get('conversations', [ConversationController::class, 'index'])
        ->middleware('ability:conversations:read')
        ->name('api.conversations.index');

    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->middleware('ability:conversations:read')
        ->whereNumber('conversation')
        ->name('api.conversations.show');

    Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])
        ->middleware('ability:conversations:reply')
        ->whereNumber('conversation')
        ->name('api.conversations.update');

    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])
        ->middleware('ability:conversations:reply')
        ->whereNumber('conversation')
        ->name('api.conversations.read');

    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])
        ->middleware('ability:conversations:read')
        ->whereNumber('conversation')
        ->name('api.conversations.messages');

    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])
        ->middleware('ability:conversations:reply')
        ->whereNumber('conversation')
        ->name('api.conversations.reply');

    Route::get('leads', [LeadController::class, 'index'])
        ->middleware('ability:leads:read')
        ->name('api.leads.index');

    Route::get('leads/{lead}', [LeadController::class, 'show'])
        ->middleware('ability:leads:read')
        ->whereNumber('lead')
        ->name('api.leads.show');

    Route::patch('leads/{lead}', [LeadController::class, 'update'])
        ->middleware('ability:leads:write')
        ->whereNumber('lead')
        ->name('api.leads.update');

    Route::post('leads/{lead}/whatsapp', [LeadController::class, 'whatsapp'])
        ->middleware('ability:leads:write')
        ->whereNumber('lead')
        ->name('api.leads.whatsapp');
});
