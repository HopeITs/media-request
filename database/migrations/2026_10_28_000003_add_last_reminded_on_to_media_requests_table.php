<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a deadline reminder last went out for a request (php artisan media:remind),
 * so a request is reminded about at most once a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->date('last_reminded_on')->nullable()->after('last_nudged_at');
        });
    }

    public function down(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->dropColumn('last_reminded_on');
        });
    }
};
