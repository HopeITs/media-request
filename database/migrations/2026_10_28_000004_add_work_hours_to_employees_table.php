<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part-time team members: the hours they work, e.g. "10:00-14:00" (empty = full time,
 * the team's day). A longer job title, since one person may do several things:
 * "Videographer / Editor / Photographer (on need) / Animation (on need)".
 *
 *   employees.work_hours       "HH:MM-HH:MM" | null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_hours', 11)->nullable()->after('position_title')->comment('Part time only, e.g. "10:00-14:00"');
            $table->string('position_title', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('work_hours');
        });
    }
};
