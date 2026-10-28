<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaRequestComment extends Model
{
    protected $fillable = ['media_request_id', 'employee_id', 'body'];

    public function mediaRequest(): BelongsTo
    {
        return $this->belongsTo(MediaRequest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
