<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone stand-in for the HR position: the job title, as in the HR app
 * ("Communication team member / Photographer"). The Media team specialty is
 * read from it, the same way the HR app reads it from the position title.
 *
 *   employees.position_title   free text | null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('position_title', 120)->nullable()->after('media_role')->comment('Job title, e.g. "Communication team member / Photographer"');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('position_title');
        });
    }
};
