<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Postgres does not index foreign keys on its own; these columns are filtered on every listing and every event. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('icps', fn (Blueprint $table) => $table->index('project_id'));
        Schema::table('search_jobs', fn (Blueprint $table) => $table->index('icp_id'));
        Schema::table('leads', fn (Blueprint $table) => $table->index('icp_id'));
        Schema::table('webhooks', fn (Blueprint $table) => $table->index('project_id'));
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('icps', fn (Blueprint $table) => $table->dropIndex(['project_id']));
        Schema::table('search_jobs', fn (Blueprint $table) => $table->dropIndex(['icp_id']));
        Schema::table('leads', fn (Blueprint $table) => $table->dropIndex(['icp_id']));
        Schema::table('webhooks', fn (Blueprint $table) => $table->dropIndex(['project_id']));
    }
};
