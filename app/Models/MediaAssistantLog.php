<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sentence typed to the request assistant, and how it went. */
class MediaAssistantLog extends Model
{
    protected $fillable = [
        'employee_id', 'text', 'understood', 'service', 'type', 'answers', 'program_id', 'city_id',
        'needed_by', 'asked', 'media_request_id', 'final_service', 'final_type', 'corrected',
    ];

    protected $casts = [
        'understood' => 'boolean',
        'corrected' => 'boolean',
        'answers' => 'array',
        'asked' => 'array',
        'needed_by' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function mediaRequest(): BelongsTo
    {
        return $this->belongsTo(MediaRequest::class);
    }
}
