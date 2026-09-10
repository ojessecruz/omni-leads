<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 50);
            $table->string('external_id');
            $table->jsonb('raw_payload');
            $table->timestamps();

            $table->index(['driver', 'external_id']);
            $table->unique(['lead_id', 'driver', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
