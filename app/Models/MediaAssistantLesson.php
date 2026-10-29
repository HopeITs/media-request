<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A word the media manager taught the request assistant, and what it means (see MediaAssistant). */
class MediaAssistantLesson extends Model
{
    protected $fillable = ['phrase', 'service', 'type', 'format', 'program_id', 'city_id', 'employee_id'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** Lower case, single spaces: how phrases are compared. */
    public static function normal(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
