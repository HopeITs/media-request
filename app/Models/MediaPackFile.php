<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A change to HC_PACK made in the app: a file added or replaced (kept here), or one taken out. */
class MediaPackFile extends Model
{
    protected $fillable = ['path', 'stored', 'size', 'removed', 'user_id'];

    protected $casts = ['removed' => 'boolean', 'size' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
