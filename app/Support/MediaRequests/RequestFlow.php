<?php

namespace App\Support\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The "request flow" card: where a person's requests are, stage by stage,
 * in one line ("5 requests: 3 delivered, 1 rejected, 1 waiting for approval")
 * and as a strip of stages with the requests in each.
 *
 * Whose requests:
 *   media manager / administrator  all of them
 *   team member                    the ones they hold, and those waiting to be taken
 *   program manager                their programs' (and their own)
 *   anyone else                    their own
 * Open requests, plus those finished (delivered, rejected, cancelled) in the last 30 days.
 */
class RequestFlow
{
    /** Stages in order: the board's columns, then the ones that stopped. */
    public const STAGES = ['approval', 'claim', 'work', 'int', 'req', 'finish', 'done', 'stopped'];

    public const ICONS = ['approval' => 'shield', 'claim' => 'inbox', 'work' => 'pen', 'int' => 'eye', 'req' => 'user',
        'finish' => 'printer', 'done' => 'check', 'stopped' => 'x'];

    public const RECENT_DAYS = 30;

    public function __construct(private MediaTeam $team) {}

    /** @return array{scope: string, total: int, stages: array<string, Collection>, waitingYou: int} */
    public function for(User $user): array
    {
        [$scope, $query] = $this->scoped($user);

        $requests = $query
            ->where(fn ($q) => $q->whereIn('status', S::open())
                ->orWhere('status_changed_at', '>=', now()->subDays(self::RECENT_DAYS)))
            ->with(['assignee', 'program'])
            ->orderBy('needed_by')
            ->limit(300)->get();

        $stages = collect(self::STAGES)->mapWithKeys(fn ($s) => [$s => collect()])->all();
        foreach ($requests as $r) {
            $stages[$this->stage($r)]->push($r);
        }

        return [
            'scope' => $scope,
            'total' => $requests->count(),
            'stages' => $stages,
            // Open requests that wait on this person right now (their turn).
            'waitingYou' => $requests->filter(fn (MediaRequest $r) => $r->isOpen() && \Illuminate\Support\Facades\Gate::forUser($user)->allows('isOwner', $r))->count(),
        ];
    }

    public function stage(MediaRequest $r): string
    {
        return $r->boardColumn() ?? 'stopped';
    }

    /** @return array{0: string, 1: \Illuminate\Database\Eloquent\Builder} */
    private function scoped(User $user): array
    {
        $q = MediaRequest::query();

        if ($this->team->canActAsMediaManager($user) || $this->team->isAdministrator($user)) {
            return ['all', $q];
        }

        if ($this->team->isMember($user->profile)) {
            $me = $user->employee_id;

            return ['team', $q->where(fn ($w) => $w->where('assignee_id', $me)->orWhere('status', S::ToClaim))];
        }

        $programs = $this->team->managedProgramIds($user->profile);
        if ($programs !== []) {
            return ['programs', $q->where(fn ($w) => $w->whereIn('program_id', $programs)->orWhere('requester_id', $user->employee_id))];
        }

        return ['mine', $q->where('requester_id', $user->employee_id)];
    }
}
