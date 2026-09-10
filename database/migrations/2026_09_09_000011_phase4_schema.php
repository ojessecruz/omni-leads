<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A lead may belong to several ICPs of the same project. leads.icp_id becomes the "originating ICP".
        Schema::create('icp_lead', function (Blueprint $table) {
            $table->foreignId('icp_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['icp_id', 'lead_id']);
            $table->index('lead_id');
        });

        DB::statement('INSERT INTO icp_lead (icp_id, lead_id, created_at) SELECT icp_id, id, created_at FROM leads');

        // Dedup becomes per project.
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['icp_id', 'dedup_hash']);
            $table->unique(['project_id', 'dedup_hash']);
        });

        Schema::table('icps', function (Blueprint $table) {
            $table->unsignedSmallInteger('rerun_every_days')->nullable()->after('criteria');
            $table->timestamp('last_run_at')->nullable()->after('rerun_every_days');
            $table->timestamp('next_run_at')->nullable()->after('last_run_at');
            $table->index('next_run_at');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->text('whatsapp_template')->nullable()->after('avg_ticket');
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('secret', 64);
            $table->jsonb('events');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('failures_count')->default(0);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('whatsapp_template'));
        Schema::table('icps', function (Blueprint $table) {
            $table->dropIndex(['next_run_at']);
            $table->dropColumn(['rerun_every_days', 'last_run_at', 'next_run_at']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'dedup_hash']);
            $table->unique(['icp_id', 'dedup_hash']);
        });
        Schema::dropIfExists('icp_lead');
    }
};
