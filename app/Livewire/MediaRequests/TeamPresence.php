<?php

namespace App\Livewire\MediaRequests;

use App\Support\MediaRequests\MediaTeam;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Who of the media team is online now, on a big clock card. "Online" is a request
 * to the app in the last few minutes (the sessions table: SESSION_DRIVER=database);
 * the others show when they were last seen. Read only; it refreshes every minute
 * while it is on screen.
 */
class TeamPresence extends Component
{
    public function render()
    {
        $members = app(MediaTeam::class)->members();
        $userIds = $members->pluck('account.id')->filter()->all();

        $seen = config('session.driver') === 'database' && $userIds
            ? DB::table(config('session.table', 'sessions'))->whereIn('user_id', $userIds)
                ->groupBy('user_id')->selectRaw('user_id, max(last_activity) as at')->pluck('at', 'user_id')
            : collect();

        $since = now()->subMinutes((int) config('media-requests.workday.online_minutes', 5))->getTimestamp();
        $locale = app()->getLocale();

        $people = $members->map(function ($e) use ($seen, $since, $locale) {
            $at = $e->account ? ($seen[$e->account->id] ?? null) : null;

            return [
                'id' => $e->id,
                'name' => $e->nameForLocale($locale),
                'online' => $at !== null && (int) $at >= $since,
                'seen' => $at !== null ? Carbon::createFromTimestamp((int) $at)->diffForHumans() : null,
            ];
        })->sortBy(fn ($p) => [$p['online'] ? 0 : 1, $p['name']])->values();

        return view('livewire.media-requests.team-presence', ['people' => $people]);
    }
}
