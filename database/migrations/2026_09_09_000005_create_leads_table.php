<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('icp_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->boolean('whatsapp_valid')->nullable();
            $table->string('country', 2);
            $table->string('city')->nullable();
            $table->string('cnpj', 14)->nullable()->index();
            $table->unsignedTinyInteger('score')->default(0)->index();
            $table->string('status', 20)->default('new')->index();
            $table->string('dedup_hash', 40);
            $table->timestamps();

            // Dedup per ICP: cross-ICP merge is left to Phase 4.
            $table->unique(['icp_id', 'dedup_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
