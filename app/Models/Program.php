<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Programs are shown by code + slug and never translated. */
class Program extends Model
{
    protected $fillable = ['name', 'code', 'slug', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'program_managers');
    }
}
