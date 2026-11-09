<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media_requests.review_alerted_at   when the media manager was told a result has waited for their check
 *                                    half a working day (media:review-alert); once for each time it is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->timestamp('review_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->dropColumn('review_alerted_at');
        });
    }
};
