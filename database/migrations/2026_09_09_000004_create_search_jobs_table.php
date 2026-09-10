<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('icp_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 50)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->jsonb('params');
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_jobs');
    }
};
