<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $fillable = [
        'city_id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar',
        'username', 'media_role', 'position_title', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** Same as the HR app: the name in the asked language, falling back to the other. */
    public function nameForLocale(string $locale): string
    {
        $locale = in_array($locale, ['en', 'ar'], true) ? $locale : app()->getLocale();
        $name = trim(implode(' ', array_filter([
            $this->getAttribute("first_name_{$locale}"),
            $this->getAttribute("last_name_{$locale}"),
        ])));

        return $name !== '' ? $name : trim($this->first_name_en.' '.$this->last_name_en);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function managedPrograms(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_managers');
    }
}
