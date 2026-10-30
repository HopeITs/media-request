<?php

namespace App\Livewire\MediaRequests;

use App\Support\MediaRequests\MediaTeam;
use Livewire\Component;

/**
 * Who of the media team is online now, on a big clock card: used the app in the
 * last few minutes (users.last_seen_at); the others show when they were last
 * seen. Read only; it refreshes every minute while it is on screen.
 */
class TeamPresence extends Component
{
    public function render()
    {
        $locale = app()->getLocale();

        $people = app(MediaTeam::class)->members()->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->nameForLocale($locale),
            'online' => (bool) $e->account?->isOnline(),
            'seen' => $e->account?->last_seen_at?->diffForHumans(),
        ])->sortBy(fn ($p) => [$p['online'] ? 0 : 1, $p['name']])->values();

        return view('livewire.media-requests.team-presence', ['people' => $people]);
    }
}
