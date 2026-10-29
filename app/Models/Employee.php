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
        'username', 'media_role', 'position_title', 'work_hours', 'is_active',
    ];

    /**
     * Someone already here under this English name ("George Tello"), e.g. brought in from the
     * old sheet without an account; with $unclaimed, only people who have no account yet.
     */
    public static function findByName(string $name, bool $unclaimed = false): ?self
    {
        $first = mb_strtolower(trim(\Illuminate\Support\Str::before(trim($name), ' ')));
        $last = mb_strtolower(trim(\Illuminate\Support\Str::contains(trim($name), ' ') ? \Illuminate\Support\Str::after(trim($name), ' ') : ''));

        return self::query()
            ->whereRaw('lower(first_name_en) = ?', [$first])
            ->whereRaw("lower(coalesce(last_name_en, '')) = ?", [$last])
            ->when($unclaimed, fn ($q) => $q->whereDoesntHave('account'))
            ->orderBy('id')->first();
    }

    /** Part of a working day this person works: 1 full time, 0.5 for four hours. */
    public function dayShare(): float
    {
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?-(\d{1,2})(?::(\d{2}))?$/', (string) $this->work_hours, $m)) {
            return 1.0;
        }
        $hours = ((int) $m[3] + (int) ($m[4] ?? 0) / 60) - ((int) $m[1] + (int) ($m[2] ?? 0) / 60);

        return $hours > 0 ? min(1.0, round($hours / 8, 2)) : 1.0;
    }

    /**
     * This person's working hours as minutes after midnight: their own (part time) or the usual day.
     *
     * @return array{0: int, 1: int}
     */
    public function workMinutes(): array
    {
        foreach ([(string) $this->work_hours, (string) config('media-requests.workday.hours')] as $hours) {
            if (preg_match('/^(\d{1,2})(?::(\d{2}))?-(\d{1,2})(?::(\d{2}))?$/', $hours, $m)) {
                [$from, $to] = [(int) $m[1] * 60 + (int) ($m[2] ?? 0), (int) $m[3] * 60 + (int) ($m[4] ?? 0)];
                if ($to > $from) {
                    return [$from, $to];
                }
            }
        }

        return [8 * 60, 16 * 60];
    }

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
