<?php

namespace App\Livewire\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * My requests and my programs' requests. Whatever is waiting on the person
 * looking at the screen comes first, then by priority and needed-by date.
 */
class Index extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'service')]
    public string $service = '';

    public function setActiveTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'action', 'open', 'done'], true) ? $tab : 'all';
    }

    #[On('media-request-saved')]
    public function refreshList(): void
    {
        // Re-render after the wizard saved a request.
    }

    /** People who only ask for work have the home screen only. */
    public function mount(): void
    {
        if (app(\App\Support\MediaRequests\MediaTeam::class)->isRequesterOnly(auth()->user())) {
            $this->redirectRoute('media-requests.home');
        }
    }

    public function render()
    {
        $requests = MediaRequest::query()
            ->with(['requester', 'assignee', 'program', 'city'])
            ->relevantTo(auth()->user())
            ->when($this->service !== '', fn (Builder $q) => $q->where('service', $this->service))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w->where('reference', 'like', $term)->orWhere('title', 'like', $term));
            })
            ->latest('id')
            ->limit(300)
            ->get();

        $mine = fn (MediaRequest $r): bool => Gate::allows('isOwner', $r);

        $counts = [
            'all' => $requests->count(),
            'action' => $requests->filter($mine)->count(),
            'open' => $requests->filter->isOpen()->count(),
            'done' => $requests->where('status', S::Delivered)->count(),
        ];

        $visible = match ($this->tab) {
            'action' => $requests->filter($mine),
            'open' => $requests->filter->isOpen(),
            'done' => $requests->where('status', S::Delivered),
            default => $requests,
        };

        return view('livewire.media-requests.index', [
            'requests' => $this->sorted($visible, $mine),
            'counts' => $counts,
            'mine' => $mine,
        ]);
    }

    /** Needs you first, then priority, then the nearest needed-by date. */
    private function sorted(Collection $requests, callable $mine): Collection
    {
        $weight = ['high' => 0, 'normal' => 1, 'low' => 2];

        return $requests->sortBy([
            fn (MediaRequest $a, MediaRequest $b) => (int) $mine($b) <=> (int) $mine($a),
            fn (MediaRequest $a, MediaRequest $b) => (int) $b->isOpen() <=> (int) $a->isOpen(),
            fn (MediaRequest $a, MediaRequest $b) => ($weight[$a->priority] ?? 1.5) <=> ($weight[$b->priority] ?? 1.5),
            fn (MediaRequest $a, MediaRequest $b) => ($a->needed_by?->timestamp ?? PHP_INT_MAX) <=> ($b->needed_by?->timestamp ?? PHP_INT_MAX),
        ])->values();
    }
}
