<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What people type to the request assistant, what it understood, and what
 * they finally asked for. Used to teach the assistant new words: rows it did
 * not understand, and rows where the person changed its guess, are exactly
 * the phrases its keyword lists are missing.
 *
 * Not shown to requesters. Readable only with media-requests.view-log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('text');

            // What the assistant understood.
            $table->boolean('understood')->default(false);
            $table->string('service', 3)->nullable();
            $table->string('type', 20)->nullable();
            $table->json('answers')->nullable();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->date('needed_by')->nullable();
            $table->json('asked')->nullable()->comment('Follow-up questions it still had to ask');

            // What the person finally sent.
            $table->foreignId('media_request_id')->nullable()->constrained('media_requests')->nullOnDelete();
            $table->string('final_service', 3)->nullable();
            $table->string('final_type', 20)->nullable();
            $table->boolean('corrected')->default(false)->comment('The person picked a different service or type');

            $table->timestamps();
            $table->index(['understood', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assistant_logs');
    }
};
