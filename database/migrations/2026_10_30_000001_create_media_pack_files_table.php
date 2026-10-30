<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HC_PACK changes made in the app by the media manager, over the index of the
 * shared drive (resources/data/hc-pack.json):
 *   media_pack_files.path       the file's place in the pack, "001 Logos/001 Projects/001 HC/001_HC.svg"
 *   media_pack_files.stored     where the uploaded file is kept (local disk, private); null when removed
 *   media_pack_files.size       bytes
 *   media_pack_files.removed    true: the file is taken out of the pack
 *   media_pack_files.user_id    who made the change
 * A row with a file adds that path, or replaces the drive's file of the same path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_pack_files', function (Blueprint $table) {
            $table->id();
            $table->string('path', 400)->unique();
            $table->string('stored', 500)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('removed')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_pack_files');
    }
};
