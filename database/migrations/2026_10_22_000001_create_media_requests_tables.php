<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Media Requests: what the Media team (Communication / CT) receives from
 * everyone else — design (001), photography (002), videography (003) and
 * ready files from HC_PACK (000).
 *
 * Every open status has exactly one owner (see MediaRequestStatus::owner()),
 * and status_changed_at is what the "late" flag and the board's "for how long"
 * are measured from. The full history lives in activity_log (log name
 * "media-requests"); comments are the conversation between requester and team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique()->comment('MR-{service}-{yy}-{0001}');

            $table->foreignId('requester_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();

            $table->string('service', 3)->comment('001 design, 002 photo, 003 video, 000 HC_PACK');
            $table->string('type', 20);
            $table->string('item_code', 40)->comment('Certificate, ID, Photos… part of the archive name');
            $table->string('title', 120)->comment('Short name typed after the automatic prefix');
            $table->text('description')->nullable();
            $table->json('answers')->nullable()->comment('Answers to the type-specific questions');
            $table->json('pack_files')->nullable()->comment('HC_PACK paths for service 000');

            $table->date('needed_by')->nullable();
            $table->text('rush_reason')->nullable();
            $table->string('delivery_channel', 20)->nullable();
            $table->string('delivery_contact', 190)->nullable();

            $table->string('status', 20)->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('return_to', 20)->nullable()->comment('Status to resume after needs_info / on_hold');
            $table->string('held_by', 20)->nullable()->comment('Which side put it on hold');

            $table->boolean('needs_program_approval')->default(true);
            $table->foreignId('program_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('program_approved_at')->nullable();
            $table->foreignId('media_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('media_approved_at')->nullable();

            $table->foreignId('assignee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('priority', 10)->nullable();
            $table->date('planned_for')->nullable();
            $table->boolean('internal_ok')->default(false)->comment('After the first media-manager check, revisions go straight to the requester');
            $table->unsignedSmallInteger('revisions')->default(0);

            $table->string('draft_link', 500)->nullable();
            $table->string('result_link', 500)->nullable();
            $table->string('archive_link', 500)->nullable();
            $table->string('archive_name', 160)->nullable();

            $table->timestamp('last_nudged_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['service', 'status']);
            $table->index(['program_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('media_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_request_comments');
        Schema::dropIfExists('media_requests');
    }
};
