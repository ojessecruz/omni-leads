<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One cold-mail connector per project; the key lives in the owner's integrations (BYOK).
        Schema::create('outbound_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('connector');
            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->boolean('auto_push')->default(false);
            $table->string('webhook_id')->nullable();
            $table->text('webhook_secret');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_connections');
    }
};
