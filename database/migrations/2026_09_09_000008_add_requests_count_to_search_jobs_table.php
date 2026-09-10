<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_jobs', function (Blueprint $table) {
            $table->unsignedInteger('requests_count')->default(0)->after('results_count');
        });
    }

    public function down(): void
    {
        Schema::table('search_jobs', function (Blueprint $table) {
            $table->dropColumn('requests_count');
        });
    }
};
