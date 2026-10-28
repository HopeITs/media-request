<?php

namespace App\Console\Commands;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Services\MediaRequestNotifier;
use App\Support\MediaRequests\WorkCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deadline reminders, once a working day (the schedule in routes/console.php runs it at 08:30, Damascus):
 *
 *   one working day before the needed-by date  "due tomorrow" to whoever holds the request
 *   on the needed-by date                      "due today" to whoever holds it
 *   after it, every working day                "overdue" to whoever holds it, the assignee and the media manager
 *
 * Requests that are paused (on hold) are skipped. Each request is reminded at most once a day.
 *
 *   php artisan media:remind
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'media:remind';

    protected $description = 'Remind whoever holds a request before and after its needed-by date';

    public function handle(WorkCalendar $calendar, MediaRequestNotifier $notifier): int
    {
        $today = now()->setTimezone((string) config('media-requests.work.timezone', 'Asia/Damascus'))->startOfDay();
        if (! $calendar->isWorkDay($today)) {
            $this->info('Not a working day: no reminders.');

            return self::SUCCESS;
        }

        // The next working day, for "due tomorrow".
        $next = $today->copy()->addDay();
        while (! $calendar->isWorkDay($next)) {
            $next->addDay();
        }

        $sent = 0;
        $due = MediaRequest::query()
            ->whereIn('status', S::open())
            ->where('status', '!=', S::OnHold)
            ->whereNotNull('needed_by')
            ->whereDate('needed_by', '<=', $next->toDateString())
            ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', $today->toDateString()))
            ->with(['requester', 'assignee', 'program'])
            ->get();

        foreach ($due as $r) {
            $day = Carbon::parse($r->needed_by)->startOfDay();
            $event = $day->lt($today) ? 'overdue' : ($day->eq($today) ? 'dueToday' : 'dueSoon');

            $notifier->event($r, $event, null, ['date' => $r->needed_by->format('d-m-Y')]);
            $r->forceFill(['last_reminded_on' => $today->toDateString()])->saveQuietly();
            $sent++;
        }

        $this->info("Reminders sent for {$sent} request(s).");

        return self::SUCCESS;
    }
}
