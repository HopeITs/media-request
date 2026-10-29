<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Something the media manager taught the request assistant: the words people write
 * (a phrase and its synonyms), and what they mean: a kind of request, a tool on
 * HC Generator, a ready file in HC_PACK, or something else with a reply for the person.
 * A program and a governorate can come with any of them. See MediaAssistant.
 */
class MediaAssistantLesson extends Model
{
    public const MEANINGS = ['request', 'generator', 'pack', 'reply'];

    protected $fillable = ['phrase', 'keywords', 'meaning', 'service', 'type', 'format', 'tool', 'pack_query', 'reply', 'program_id', 'city_id', 'employee_id'];

    protected $casts = ['keywords' => 'array'];

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

    /** Lower case, single spaces: how words are compared. */
    public static function normal(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }

    /** @return array<int, string> the phrase and its synonyms, compared form */
    public function words(): array
    {
        return array_values(array_filter(array_unique(array_map(fn ($w) => self::normal((string) $w), [$this->phrase, ...($this->keywords ?? [])])), fn ($w) => mb_strlen($w) >= 2));
    }

    /** The longest word of this lesson found in the text, or null. */
    public function foundIn(string $text): ?string
    {
        $text = self::normal($text);
        $hits = array_filter($this->words(), fn ($w) => str_contains($text, $w));
        usort($hits, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $hits[0] ?? null;
    }

    /**
     * Lessons whose words are in the text, the one with the longest match first.
     *
     * @return Collection<int, self>
     */
    public static function matching(string $text, ?string $meaning = null): Collection
    {
        // One lesson may mean several things at once ("ريل": a video request, and Reels Gen too).
        $field = ['request' => 'service', 'generator' => 'tool', 'pack' => 'pack_query', 'reply' => 'reply'][$meaning] ?? null;

        return self::query()->get()->when($field, fn ($c) => $c->filter(fn (self $l) => filled($l->{$field})))
            ->map(fn (self $l) => [$l, $l->foundIn($text)])->filter(fn ($p) => $p[1] !== null)
            ->sortByDesc(fn ($p) => mb_strlen($p[1]))->map(fn ($p) => $p[0])->values();
    }
}
