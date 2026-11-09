<?php

namespace App\Console\Commands;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Services\MediaRequestNotifier;
use App\Support\MediaRequests\WorkCalendar;
use Illuminate\Console\Command;

/**
 * A result waiting for the media manager's check for half a working day (config
 * media-requests.review_alert_minutes) tells the media manager, once each time a result
 * is sent. In the old review group a quarter of the designs waited more than 16 hours.
 * The schedule in routes/console.php runs it every half hour in working hours.
 *
 *   php artisan media:review-alert
 */
class SendReviewAlerts extends Command
{
    protected $signature = 'media:review-alert';

    protected $description = 'Tell the media manager when a result has waited half a working day for their check';

    public function handle(WorkCalendar $calendar, MediaRequestNotifier $notifier): int
    {
        $sent = 0;
        $waiting = MediaRequest::query()
            ->where('status', S::InternalReview)
            ->whereNotNull('status_changed_at')
            ->where(fn ($q) => $q->whereNull('review_alerted_at')->orWhereColumn('review_alerted_at', '<', 'status_changed_at'))
            ->get();

        foreach ($waiting as $r) {
            if (! $r->reviewIsSlow($calendar)) {
                continue;
            }
            $notifier->event($r, 'reviewWaiting', null);
            $r->forceFill(['review_alerted_at' => now()])->saveQuietly();
            $sent++;
        }

        $this->info("Review alerts sent for {$sent} request(s).");

        return self::SUCCESS;
    }
}
