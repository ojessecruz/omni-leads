<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('remote_jid');
            $table->string('phone', 32);
            $table->string('name')->nullable();
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'remote_jid']);
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('whatsapp_conversation_lead', function (Blueprint $table) {
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->index()->constrained()->cascadeOnDelete();

            $table->primary(['whatsapp_conversation_id', 'lead_id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->boolean('from_me');
            $table->string('sent_via', 10)->nullable();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_name')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['whatsapp_conversation_id', 'external_id']);
            $table->index(['whatsapp_conversation_id', 'sent_at']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversation_lead');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
