<?php

namespace App\Support\MediaRequests;

use Illuminate\Support\Carbon;

/**
 * The media team's working time, for deadlines.
 *
 * The team works Monday to Friday, 9:00 to 17:00 (Damascus). A request counts
 * from the moment the team can start on it: sent on a working day before
 * 13:00, that day counts; later, or on a weekend, it starts the next working
 * day. Each request type takes a number of working days (config
 * media-requests.work.turnaround); anything done outside (print shop, market)
 * then also needs days when the market is open, which closes on Friday.
 *
 *   Friday 16:30, new design (4 days)  ->  Monday, Tuesday, Wednesday, Thursday
 */
class WorkCalendar
{
    /**
     * The earliest date the team can deliver this request, if sent at $now.
     * $waitDays: working days it waits first because the team is busy (TeamLoad).
     */
    public function earliest(?string $service, ?string $type, array $answers = [], ?Carbon $now = null, int $waitDays = 0): ?Carbon
    {
        $plan = $this->plan($service, $type, $answers);
        if ($plan === null) {
            return null;
        }

        [$teamDays, $outsideDays] = $plan;
        $teamDays += max(0, $waitDays);
        $day = $this->firstDay($now);

        // The first working day counts as day one.
        $day = $this->addDays($day, max(0, $teamDays - 1), $this->workDays());
        if ($outsideDays > 0) {
            $day = $this->addDays($day->copy()->addDay(), $outsideDays - 1, $this->outsideDays());
        }

        return $day->startOfDay();
    }

    /**
     * [team working days, outside days] for a request type, or null when the type is unknown.
     *
     * @return array{0: int, 1: int}|null
     */
    public function plan(?string $service, ?string $type, array $answers = []): ?array
    {
        $plan = config('media-requests.work.turnaround')[$service.'.'.$type] ?? null;
        if ($plan === null) {
            return null;
        }

        [$team, $outside] = [(int) $plan[0], (int) $plan[1]];
        if ($service === '001' && $type === 'new' && ($answers['alsoPrint'] ?? null) === 'yes') {
            $outside += (int) config('media-requests.work.print_with_design', 2);
        }

        return [max(1, $team), $outside];
    }

    public function needsOutside(?string $service, ?string $type, array $answers = []): bool
    {
        return ($this->plan($service, $type, $answers)[1] ?? 0) > 0;
    }

    public function isWorkDay(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), $this->workDays(), true);
    }

    /** The day work can start: today if it is a working day and early enough, else the next working day. */
    public function firstDay(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy()->setTimezone($this->tz());
        [$h, $m] = array_map('intval', explode(':', (string) config('media-requests.work.same_day_until', '13:00')));

        $day = $now->copy()->startOfDay();
        if (! $this->isWorkDay($day) || $now->hour * 60 + $now->minute >= $h * 60 + $m) {
            $day = $this->next($day->addDay(), $this->workDays());
        }

        return $day;
    }

    /** Working days from today to $date, counting $date; 0 when it is past. */
    public function workDaysUntil(Carbon $date, ?Carbon $now = null): int
    {
        $n = 0;
        for ($d = $this->firstDay($now); $d->lte($date->copy()->startOfDay()); $d->addDay()) {
            $n += $this->isWorkDay($d) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<int, int> */
    private function workDays(): array
    {
        return config('media-requests.work.days', [1, 2, 3, 4, 5]);
    }

    /** @return array<int, int> */
    private function outsideDays(): array
    {
        return config('media-requests.work.outside_days', [1, 2, 3, 4]);
    }

    private function tz(): string
    {
        return (string) config('media-requests.work.timezone', 'Asia/Damascus');
    }

    /** The first allowed day on or after $day. */
    private function next(Carbon $day, array $allowed): Carbon
    {
        while (! in_array($day->isoWeekday(), $allowed, true)) {
            $day->addDay();
        }

        return $day;
    }

    /** Move forward $n allowed days from $day (which is moved onto an allowed day first). */
    private function addDays(Carbon $day, int $n, array $allowed): Carbon
    {
        $day = $this->next($day->copy(), $allowed);
        while ($n > 0) {
            $day = $this->next($day->addDay(), $allowed);
            $n--;
        }

        return $day;
    }
}
