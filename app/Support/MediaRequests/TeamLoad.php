<?php

namespace App\Support\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;

/**
 * How busy the media team is, per specialty, so a new request's default date
 * is honest: when everyone in design already has a magazine and two posters,
 * a new brochure waits a day or two before anyone can start it.
 *
 * Each open request weighs its "effort" (1 = a poster, 3 = a magazine, half
 * for a small edit). The people of a specialty can carry about "capacity" each
 * at once; whatever is above that is spread over them, one unit per person a
 * day. Settings: config media-requests.work.effort, capacity, max_extra_days.
 */
class TeamLoad
{
    /** Statuses where the work is still ahead of the team. */
    private const WORKING = [S::MediaApproval, S::ToClaim, S::Assigned, S::InProgress, S::InternalReview];

    public function __construct(private MediaTeam $team) {}

    /** Rough size of one request. */
    public function effort(?string $service, ?string $type, array $answers = []): float
    {
        $rule = config('media-requests.work.effort')[$service.'.'.$type] ?? 1;
        if (! is_array($rule)) {
            return (float) $rule;
        }

        if (($answers['brochureType'] ?? null) === 'booklet' && isset($rule['booklet'])) {
            return (float) $rule['booklet'];
        }

        return (float) ($rule['format'][$answers['format'] ?? ''] ?? $rule['default'] ?? 1);
    }

    /**
     * The load on a specialty now.
     *
     * @return array{units: float, people: int, requests: int, extraDays: int}
     */
    public function of(?string $specialty): array
    {
        $open = MediaRequest::query()->whereIn('status', self::WORKING)->get(['id', 'service', 'type', 'answers', 'status'])
            ->filter(fn (MediaRequest $r) => $r->specialty() === $specialty);

        $units = $open->sum(fn (MediaRequest $r) => $this->effort($r->service, $r->type, $r->answers ?? []));
        $members = $this->team->members();
        $specialists = $members->filter(fn ($e) => $this->team->hasSkill($e, $specialty));
        // A part-timer counts as part of a person (four hours = half).
        $people = max(1, (int) round(($specialists->isNotEmpty() ? $specialists : $members)->sum(fn ($e) => $e->dayShare())));

        $capacity = (float) config('media-requests.work.capacity', 2);
        $over = $units - $capacity * $people;
        $extra = $over > 0 ? (int) ceil($over / $people) : 0;

        return [
            'units' => round($units, 1),
            'people' => $people,
            'requests' => $open->count(),
            'extraDays' => min($extra, (int) config('media-requests.work.max_extra_days', 5)),
        ];
    }
}
