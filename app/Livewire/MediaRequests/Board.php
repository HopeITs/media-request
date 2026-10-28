<?php

namespace App\Livewire\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Support\MediaRequests\MediaTeam;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Media team's board: every request by stage, each card with who has it
 * and for how long. Requests waiting for an answer or on hold stay in the
 * column they paused in. Delivered shows the last 30 days.
 *
 * Everyone can take any request, but each member has a specialty (design,
 * photo, video, montage): in every column the requests of their own field come
 * first, and the board can be narrowed to one field.
 */
class Board extends Component
{
    public const COLUMNS = ['approval', 'claim', 'work', 'int', 'req', 'finish', 'done'];

    #[Url(as: 'service')]
    public string $service = '';

    #[Url(as: 'mine')]
    public bool $onlyMine = false;

    /** One field only (design, photo, video, montage); empty for all. */
    #[Url(as: 'field')]
    public string $field = '';

    public function mount(): void
    {
        $team = app(MediaTeam::class);
        $user = auth()->user();

        abort_unless($team->isMember($user->profile) || $team->canActAsMediaManager($user) || $team->isAdministrator($user), 403);
    }

    public function render()
    {
        $requests = MediaRequest::query()
            ->with(['requester', 'assignee', 'program', 'city'])
            ->where(fn ($q) => $q->whereIn('status', S::open())
                ->orWhere(fn ($d) => $d->where('status', S::Delivered)->where('delivered_at', '>=', now()->subDays(30))))
            ->when($this->service !== '', fn ($q) => $q->where('service', $this->service))
            ->when($this->onlyMine, fn ($q) => $q->where('assignee_id', auth()->user()->employee_id))
            ->orderBy('needed_by')
            ->get();

        $team = app(MediaTeam::class);
        $skills = $team->skillsOf(auth()->user()->profile);
        if ($this->field !== '') {
            $requests = $requests->filter(fn (MediaRequest $r) => $r->specialty() === $this->field);
        }
        // Their own field first; the date order stays within each group.
        $requests = $requests->sortBy(fn (MediaRequest $r) => in_array($r->specialty(), $skills, true) ? 0 : 1)->values();

        $columns = collect(self::COLUMNS)->mapWithKeys(fn (string $c) => [$c => collect()])->all();

        foreach ($requests as $r) {
            if ($column = $r->boardColumn()) {
                $columns[$column]->push($r);
            }
        }

        return view('livewire.media-requests.board', ['columns' => $columns, 'skills' => $skills]);
    }
}
