<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cold email copy, filled per lead and sent to the connector as custom fields.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('email_subject_template')->nullable()->after('whatsapp_template');
            $table->text('email_template')->nullable()->after('email_subject_template');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['email_subject_template', 'email_template']));
    }
};
