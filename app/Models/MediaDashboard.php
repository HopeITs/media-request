<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's home page layout and private notes. */
class MediaDashboard extends Model
{
    protected $fillable = ['user_id', 'layout', 'notes'];

    protected $casts = ['layout' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
