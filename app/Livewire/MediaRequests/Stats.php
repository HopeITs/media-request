<?php

namespace App\Livewire\MediaRequests;

use App\Support\MediaRequests\MediaTeam;
use App\Support\MediaRequests\TeamStats;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistics for the media manager and the team: who is working, how many
 * requests and of what kind, and how deliveries went (early, on time, late,
 * sent back for changes), over the last 30, 90 or 365 days, or all time.
 */
class Stats extends Component
{
    public const PERIODS = [30, 90, 365, 0];

    #[Url(as: 'days', except: 30)]
    public int $days = 30;

    public function mount(): void
    {
        $team = app(MediaTeam::class);
        $user = auth()->user();

        abort_unless($team->isMember($user->profile) || $team->canActAsMediaManager($user) || $team->isAdministrator($user), 403);

        if (! in_array($this->days, self::PERIODS, true)) {
            $this->days = 30;
        }
    }

    public function render(TeamStats $stats)
    {
        $this->days = in_array($this->days, self::PERIODS, true) ? $this->days : 30;

        return view('livewire.media-requests.stats', [
            's' => $stats->for($this->days ?: null),
        ])->title(__('media.nav.stats'));
    }
}
