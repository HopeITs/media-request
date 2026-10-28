<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each person's own home page: which cards they show, in what order and size,
 * which are pinned, and their private notes. Kept on the server (not in the
 * browser) so the layout follows them from the office computer to the phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('layout')->nullable()->comment('[{key, size: s|m|l, pinned, hidden}] in display order');
            $table->text('notes')->nullable()->comment('Private notes; only the owner sees them');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_dashboards');
    }
};
