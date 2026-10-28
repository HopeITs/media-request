<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's proofreading checks, so they can come back to them.
 * Private: only the person who asked sees their own; they can delete them.
 *
 *   media_proofreads.source      "text" or the Word file name
 *   media_proofreads.result      changes, suggestions, summary, language, ai, local fixes (JSON)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_proofreads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 190)->default('text');
            $table->text('original');
            $table->text('corrected');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_proofreads');
    }
};
