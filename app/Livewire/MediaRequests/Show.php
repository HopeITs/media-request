<?php

namespace App\Livewire\MediaRequests;

use App\Models\MediaRequest;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaCatalog;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One request: the tracker (who has each stage, and for how long), the
 * answers, the action panel for whoever's turn it is, the conversation and
 * the history.
 *
 * Every action calls MediaRequestWorkflow, which runs the policy check for
 * this exact request and step. The permission map in access-control.php is
 * only the coarse gate in front of it.
 */
class Show extends Component
{
    use WithFileUploads;

    public MediaRequest $mediaRequest;

    public string $note = '';

    public string $link = '';

    public string $priority = 'normal';

    public ?string $plannedFor = null;

    public ?int $assigneeId = null;

    public string $body = '';

    public array $uploads = [];

    /** Files sent with a draft or a result (images, PDFs…), shown to the reviewers as previews. */
    public array $resultUploads = [];

    /** Shown in the panel on the home screen, not as its own page. */
    public bool $embedded = false;

    public function mount(MediaRequest $mediaRequest, bool $embedded = false): void
    {
        Gate::authorize('view', $mediaRequest);

        $this->embedded = $embedded;

        // People who only ask for work have the home screen only; the request opens there.
        if (! $embedded && app(MediaTeam::class)->isRequesterOnly(auth()->user())) {
            $this->redirectRoute('media-requests.home', ['open' => $mediaRequest->id]);

            return;
        }

        $this->mediaRequest = $mediaRequest;
        $this->plannedFor = $mediaRequest->planned_for?->toDateString() ?? $mediaRequest->needed_by?->toDateString();
        $this->priority = $mediaRequest->priority ?? 'normal';
    }

    /** The proofreader sends the checked message back. */
    #[On('media-proofread-apply')]
    public function applyProof(string $target, string $text): void
    {
        if ($target === 'body') {
            $this->body = mb_substr($text, 0, 5000);
        }
    }

    #[On('media-request-saved')]
    public function refreshRequest(): void
    {
        $this->mediaRequest->refresh();
    }

    // --- Approvals --------------------------------------------------------

    public function approveProgram(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->approveProgram(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function approveMedia(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->approveMedia(auth()->user(), $this->mediaRequest, $this->priority, $this->plannedFor ?: null, $this->assigneeId ?: null));
    }

    public function reject(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->reject(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function askInfo(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->askInfo(auth()->user(), $this->mediaRequest, $this->note));
    }

    // --- Team -------------------------------------------------------------

    public function assign(MediaRequestWorkflow $w): void
    {
        $this->validate(['assigneeId' => ['required', 'integer']]);
        $this->run(fn () => $w->assign(auth()->user(), $this->mediaRequest, (int) $this->assigneeId));
    }

    public function claim(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->claim(auth()->user(), $this->mediaRequest));
    }

    public function start(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->start(auth()->user(), $this->mediaRequest));
    }

    public function shareDraft(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->shareDraft(auth()->user(), $this->mediaRequest, $this->link, $this->note, $this->resultUploads));
    }

    public function sendResult(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->sendResult(auth()->user(), $this->mediaRequest, $this->link, $this->note, $this->resultUploads));
    }

    public function approveInternal(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->approveInternal(auth()->user(), $this->mediaRequest));
    }

    public function returnToDesigner(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->returnToDesigner(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function markReady(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->markReady(auth()->user(), $this->mediaRequest));
    }

    public function deliver(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->deliver(auth()->user(), $this->mediaRequest, $this->link));
    }

    public function hold(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->hold(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function resume(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->resume(auth()->user(), $this->mediaRequest));
    }

    // --- Requester --------------------------------------------------------

    public function reply(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->reply(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function approveResult(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->approveResult(auth()->user(), $this->mediaRequest));
    }

    public function requestChanges(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->requestChanges(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function cancel(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->cancel(auth()->user(), $this->mediaRequest, $this->note));
    }

    public function nudge(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->nudge(auth()->user(), $this->mediaRequest), __('media.toast.nudge', ['who' => $this->mediaRequest->ownerName()]));
    }

    // --- Conversation and files ------------------------------------------

    public function comment(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->comment(auth()->user(), $this->mediaRequest, $this->body), __('media.toast.sent'));
    }

    /** Take one chosen file back out before sending. */
    public function dropResultUpload(int $i): void
    {
        unset($this->resultUploads[$i]);
        $this->resultUploads = array_values($this->resultUploads);
    }

    public function attachFiles(MediaRequestWorkflow $w): void
    {
        $this->validate([
            'uploads' => ['required', 'array', 'max:10'],
            'uploads.*' => ['file', 'max:51200', 'mimes:jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,ai,psd,mp4,mov'],
        ]);

        $this->run(fn () => $w->attach(auth()->user(), $this->mediaRequest, $this->uploads), __('media.toast.asset'));
    }

    public function render()
    {
        [$catalog, $team, $pack] = [app(MediaCatalog::class), app(MediaTeam::class), app(HcPack::class)];
        $r = $this->mediaRequest->load(['requester', 'assignee', 'program', 'city', 'programApprover', 'mediaApprover', 'comments.author', 'media']);
        $user = auth()->user();
        $can = fn (string $ability): bool => Gate::allows($ability, $r);

        return view('livewire.media-requests.show', [
            'r' => $r,
            'catalog' => $catalog,
            'summary' => $catalog->summary($r->service, $r->type, $r->answers ?? []),
            'history' => $r->activities()->with('causer.profile')->limit(200)->get(),
            // The request's specialists first, marked, then the rest of the team; for a shoot, the team of the place first.
            'members' => $can('assign') || $can('approveMedia') ? $team->membersFor($r->specialty(), $r) : collect(),
            'specialty' => $r->specialty(),
            'team' => $team,
            'packFiles' => $r->isPack() ? $pack->find($r->pack_files ?? []) : collect(),
            'can' => $can,
            'isOwner' => $can('isOwner'),
            'isRequester' => $user->employee_id === $r->requester_id,
        ]);
    }

    /** Run one step, clear the form, and say where the request went. */
    private function run(callable $action, ?string $message = null): void
    {
        $action();

        $this->mediaRequest->refresh();
        $this->reset(['note', 'link', 'body', 'uploads', 'resultUploads', 'assigneeId']);

        LivewireAlert::title($message ?? __('media.toast.done', ['st' => \App\Enums\MediaRequestStatus::label($this->mediaRequest->status, $this->mediaRequest->type)]))
            ->success()
            ->toast()
            ->position('top-start')
            ->show();

        $this->dispatch('notification-created');

        // Delivered: a short celebration for whoever delivered it.
        if ($this->mediaRequest->status === \App\Enums\MediaRequestStatus::Delivered) {
            $r = $this->mediaRequest;
            $this->dispatch('media-celebrate', label: __($r->sameDay() ? 'media.cel.boost' : ($r->deliveryTiming() === 'early' ? 'media.cel.early' : 'media.cel.delivered')), boost: $r->sameDay());
        }
    }
}
