<?php

namespace App\Support\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Numbers for the statistics page: the team, the requests, and how deliveries went,
 * over a period (the last N days; null for all time).
 *
 *   on time   delivered on the needed-by day
 *   early     delivered before it
 *   late      delivered after it
 *   returned  sent back for changes, by the media manager or the requester
 */
class TeamStats
{
    /** Statuses where someone on the team is holding the work. */
    private const HELD = [S::Assigned, S::InProgress, S::InternalReview, S::RequesterReview, S::Production, S::Delivery, S::NeedsInfo];

    public function __construct(private MediaTeam $team, private WorkCalendar $calendar) {}

    public function for(?int $days): array
    {
        $since = $days ? now()->subDays($days)->startOfDay() : null;
        $members = $this->team->members();

        $created = MediaRequest::query()->when($since, fn ($q) => $q->where('created_at', '>=', $since))->get();
        $delivered = MediaRequest::query()->where('status', S::Delivered)->whereNotNull('delivered_at')
            ->when($since, fn ($q) => $q->where('delivered_at', '>=', $since))->get();
        $open = MediaRequest::query()->whereIn('status', S::open())->get();
        $returned = $this->returned($since);

        $timing = $delivered->groupBy(fn (MediaRequest $r) => $this->timing($r));

        return [
            'team' => [
                'members' => $members->count(),
                'working' => $members->filter(fn ($e) => $open->where('assignee_id', $e->id)->where('status', S::InProgress)->isNotEmpty())->count(),
                'holding' => $members->filter(fn ($e) => $open->where('assignee_id', $e->id)->whereIn('status', self::HELD)->isNotEmpty())->count(),
            ],
            'requests' => [
                'created' => $created->count(),
                'open' => $open->count(),
                'toClaim' => $open->where('status', S::ToClaim)->count(),
                'waitingApproval' => $open->whereIn('status', [S::ProgramApproval, S::MediaApproval])->count(),
                'delivered' => $delivered->count(),
                'rejected' => $created->where('status', S::Rejected)->count(),
                'cancelled' => $created->where('status', S::Cancelled)->count(),
            ],
            'byService' => $this->countBy($created, fn (MediaRequest $r) => $r->service),
            'byType' => $this->countBy($created, fn (MediaRequest $r) => $r->service.'.'.$r->type),
            'bySpecialty' => $this->countBy($created, fn (MediaRequest $r) => $r->specialty() ?? 'other'),
            'timing' => [
                'early' => $timing->get('early', collect())->count(),
                'onTime' => $timing->get('onTime', collect())->count(),
                'late' => $timing->get('late', collect())->count(),
            ],
            'returned' => $returned->unique()->count(),
            'avgDays' => $this->averageWorkDays($delivered),
            'people' => $members->map(fn ($e) => [
                'employee' => $e,
                'skills' => $this->team->skillsOf($e),
                'delivered' => $delivered->where('assignee_id', $e->id)->count(),
                'onTime' => $delivered->where('assignee_id', $e->id)->filter(fn ($r) => $this->timing($r) !== 'late')->count(),
                'early' => $delivered->where('assignee_id', $e->id)->filter(fn ($r) => $this->timing($r) === 'early')->count(),
                'working' => $open->where('assignee_id', $e->id)->where('status', S::InProgress)->count(),
                'holding' => $open->where('assignee_id', $e->id)->whereIn('status', self::HELD)->count(),
                'returned' => MediaRequest::whereIn('id', $returned->all())->where('assignee_id', $e->id)->count(),
            ])->sortByDesc('delivered')->values(),
        ];
    }

    /** early | onTime | late, by the delivery day against the needed-by day (see MediaRequest::deliveryTiming). */
    public function timing(MediaRequest $r): string
    {
        return $r->deliveryTiming() ?? 'onTime';
    }

    /** Ids of requests sent back for changes in the period. */
    private function returned(?Carbon $since): Collection
    {
        return Activity::query()->where('log_name', MediaRequest::LOG_NAME)
            ->whereIn('description', ['changes', 'intBack'])
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->pluck('subject_id');
    }

    /** @return array<int, array{key: string, n: int}> largest first */
    private function countBy(Collection $requests, callable $key): array
    {
        return $requests->groupBy($key)->map(fn ($g, $k) => ['key' => (string) $k, 'n' => $g->count()])
            ->sortByDesc('n')->values()->all();
    }

    /** Average working days from sending a request to its delivery. */
    private function averageWorkDays(Collection $delivered): ?float
    {
        $days = $delivered->map(function (MediaRequest $r) {
            $n = 0;
            for ($d = $r->created_at->copy()->startOfDay(); $d->lt($r->delivered_at->copy()->startOfDay()); $d->addDay()) {
                $n += $this->calendar->isWorkDay($d) ? 1 : 0;
            }

            return max(1, $n);
        });

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }
}
