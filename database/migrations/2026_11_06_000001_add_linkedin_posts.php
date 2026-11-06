<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LinkedIn posts (service 004):
 *
 * employees.linkedin_publisher   publishes the posts on the page (ticked by an administrator)
 * employees.pr_reviewer          checks or translates the post text (ticked by an administrator)
 * media_requests.text_ok_at/by   the text was checked; it runs beside the media team's work on the photos
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('linkedin_publisher')->default(false);
            $table->boolean('pr_reviewer')->default(false);
        });

        Schema::table('media_requests', function (Blueprint $table) {
            $table->timestamp('text_ok_at')->nullable();
            $table->unsignedBigInteger('text_ok_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['linkedin_publisher', 'pr_reviewer']);
        });

        Schema::table('media_requests', function (Blueprint $table) {
            $table->dropColumn(['text_ok_at', 'text_ok_by']);
        });
    }
};
