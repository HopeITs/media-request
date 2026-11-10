<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rating after an action, or a problem reported from a page, while the app is being tried out. */
class BetaFeedback extends Model
{
    protected $table = 'beta_feedback';

    protected $fillable = ['user_id', 'kind', 'rating', 'action', 'url', 'note', 'fix', 'screenshot', 'viewport', 'user_agent', 'done_at'];

    protected $casts = ['done_at' => 'datetime', 'rating' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
