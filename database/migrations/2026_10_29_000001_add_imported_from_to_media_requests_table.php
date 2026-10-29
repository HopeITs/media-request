<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests brought over from the old Google sheet (media:import-sheet). The sheet had no
 * delivery dates, so these count in the statistics by who asked, who did it and what,
 * but not in on-time / early / late.
 *
 *   media_requests.imported_from   "sheet:<row>" | null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->string('imported_from', 40)->nullable()->unique()->comment('e.g. "sheet:123" for the old Google sheet');
        });
    }

    public function down(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->dropUnique(['imported_from']);
            $table->dropColumn('imported_from');
        });
    }
};
