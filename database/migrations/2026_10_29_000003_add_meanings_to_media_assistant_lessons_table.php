<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lesson is more than a kind of request: several words for the same thing, and
 * what they lead to.
 *
 *   keywords   more words for the same thing (synonyms), besides the phrase
 *   meaning    request   a kind of media request (service, type, format)
 *              generator a tool on HC Generator, to make it yourself (tool)
 *              pack      a ready file in HC_PACK (pack_query: what to search)
 *              reply     something else: what to tell the person (reply), e.g. "Finance does this"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assistant_lessons', function (Blueprint $table) {
            $table->json('keywords')->nullable()->after('phrase');
            $table->string('meaning', 12)->default('request')->after('keywords');
            $table->string('tool', 30)->nullable()->after('format');
            $table->string('pack_query', 120)->nullable()->after('tool');
            $table->string('reply', 500)->nullable()->after('pack_query');
        });
    }

    public function down(): void
    {
        Schema::table('media_assistant_lessons', function (Blueprint $table) {
            $table->dropColumn(['keywords', 'meaning', 'tool', 'pack_query', 'reply']);
        });
    }
};
