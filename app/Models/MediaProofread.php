<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One proofreading check, kept for the person who asked (see ProofreaderPanel). */
class MediaProofread extends Model
{
    /** Older checks are removed beyond this many per person. */
    public const KEEP = 50;

    protected $fillable = ['user_id', 'source', 'original', 'corrected', 'result'];

    protected $casts = ['result' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A short first line, for the history list. */
    public function preview(int $length = 70): string
    {
        // getAttribute: $this->original inside a model is Eloquent's own copy of the attributes, not this column.
        $line = trim((string) strtok((string) $this->getAttribute('original'), "\n"));

        return mb_strlen($line) > $length ? mb_substr($line, 0, $length).'…' : $line;
    }
}
