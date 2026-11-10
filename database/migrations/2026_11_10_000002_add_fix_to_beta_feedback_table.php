<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What was changed for a feedback item, written when it is marked handled (media:feedback --done). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beta_feedback', function (Blueprint $table) {
            $table->text('fix')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('beta_feedback', function (Blueprint $table) {
            $table->dropColumn('fix');
        });
    }
};
