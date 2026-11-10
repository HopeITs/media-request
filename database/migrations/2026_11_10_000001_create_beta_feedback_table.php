<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback while the app is being tried out (temporary): a rating after an action, or a problem
 * reported from any page with a screenshot and a note. Read by the administrators on /feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beta_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('rating: after an action; report: a problem or idea from any page');
            $table->unsignedTinyInteger('rating')->nullable()->comment('1 bad, 2 so-so, 3 good (ratings only)');
            $table->string('action', 60)->nullable()->comment('What was just done, e.g. approveMedia, submit');
            $table->string('url', 500)->comment('The page, as a path');
            $table->text('note')->nullable();
            $table->string('screenshot', 120)->nullable()->comment('Path on the local (private) disk');
            $table->string('viewport', 20)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('done_at')->nullable()->comment('Looked at and handled by an administrator');
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beta_feedback');
    }
};
