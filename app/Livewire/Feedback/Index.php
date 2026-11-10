<?php

namespace App\Livewire\Feedback;

use App\Models\BetaFeedback;
use App\Support\MediaRequests\MediaTeam;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everything people sent while trying the app out: problems reported from a page (with the
 * screenshot and the note) and the ratings given after an action, newest first. Administrators
 * only; each one can be marked handled. Temporary, with config media-requests.feedback.
 */
class Index extends Component
{
    use WithPagination;

    /** open: not handled yet; report: problems only; rating: ratings only; all. */
    #[Url(as: 'show', except: 'open')]
    public string $show = 'open';

    public function mount(): void
    {
        $this->allowed();
    }

    public function setShow(string $show): void
    {
        $this->allowed();
        $this->show = in_array($show, ['open', 'report', 'rating', 'all'], true) ? $show : 'open';
        $this->resetPage();
    }

    public function toggleDone(int $id): void
    {
        $this->allowed();
        $f = BetaFeedback::findOrFail($id);
        $f->update(['done_at' => $f->done_at ? null : now()]);
    }

    public function render()
    {
        $rows = BetaFeedback::query()->with('user.profile')->latest()
            ->when($this->show === 'open', fn ($q) => $q->whereNull('done_at')->where(fn ($q) => $q->where('kind', 'report')->orWhere('rating', '<', 3)->orWhereNotNull('note')))
            ->when(in_array($this->show, ['report', 'rating'], true), fn ($q) => $q->where('kind', $this->show))
            ->paginate(25);

        // How each action is rated, worst first: where to look first.
        $byAction = BetaFeedback::query()->where('kind', 'rating')->whereNotNull('action')
            ->selectRaw('action, count(*) as n, sum(rating = 1) as bad, sum(rating = 2) as meh, sum(rating = 3) as good')
            ->groupBy('action')->get()
            ->sortByDesc(fn ($a) => ($a->bad * 2 + $a->meh) / max(1, $a->n))->values();

        return view('livewire.feedback.index', [
            'rows' => $rows,
            'byAction' => $byAction,
            'counts' => [
                'open' => BetaFeedback::query()->whereNull('done_at')->where(fn ($q) => $q->where('kind', 'report')->orWhere('rating', '<', 3)->orWhereNotNull('note'))->count(),
                'report' => BetaFeedback::query()->where('kind', 'report')->count(),
                'rating' => BetaFeedback::query()->where('kind', 'rating')->count(),
            ],
        ]);
    }

    private function allowed(): void
    {
        abort_unless(config('media-requests.feedback') && app(MediaTeam::class)->isAdministrator(auth()->user()), 403);
    }
}
