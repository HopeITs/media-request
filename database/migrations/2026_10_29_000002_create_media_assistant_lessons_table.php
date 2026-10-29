<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the media manager taught the request assistant: a word or phrase people write,
 * and what it means (the kind of request, and/or a program or governorate). The
 * assistant reads these before its own keyword rules.
 *
 *   media_assistant_lessons   phrase → service · type · format | program | city
 *   media_assistant_logs.lesson_id   the lesson taught from that line, if any
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assistant_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('phrase', 120)->comment('As people write it, e.g. "رول أب" or "كرت معايدة"');
            $table->string('service', 3)->nullable();
            $table->string('type', 20)->nullable();
            $table->string('format', 20)->nullable()->comment('Design kind for a new design: poster, cert…');
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete()->comment('Who taught it');
            $table->timestamps();
        });

        Schema::table('media_assistant_logs', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->constrained('media_assistant_lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_assistant_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');
        });
        Schema::dropIfExists('media_assistant_lessons');
    }
};
