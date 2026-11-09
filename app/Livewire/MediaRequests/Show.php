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
        // A late request: the planned day cannot be in the past, so it starts at the earliest day the team can do it.
        if ($this->plannedFor !== null && $this->plannedFor < today()->toDateString()) {
            $this->plannedFor = app(\App\Support\MediaRequests\WorkCalendar::class)
                ->earliest($mediaRequest->service, $mediaRequest->type, $mediaRequest->answers ?? [])?->toDateString() ?? today()->toDateString();
        }
        $this->priority = $mediaRequest->priority ?? 'normal';

        // Approving is one decision: the person who fits best is already chosen; the manager only changes it if they want.
        if (Gate::allows('approveMedia', $mediaRequest)) {
            $this->assigneeId = $this->suggestedAssignee($mediaRequest);
        }
    }

    /**
     * Who fits this request best: of the people whose field it is (for a shoot, the team of the place),
     * the one with the least work in hand. Null when nobody on the team fits.
     */
    private function suggestedAssignee(MediaRequest $r): ?int
    {
        $team = app(MediaTeam::class);
        $load = $this->openLoad();
        $fits = $team->membersFor($r->specialty(), $r)
            ->filter(fn ($m) => $team->onSite($r) ? $team->coversArea($m, $r) : ($r->specialty() === null || $team->hasSkill($m, $r->specialty())));
        // The media managers last: they hand the work out, they are not the first to give it to.
        $fits = $fits->reject(fn ($m) => $team->isManager($m))->whenEmpty(fn () => $fits);

        return $fits->sortBy(fn ($m) => $load[$m->id] ?? 0)->first()?->id;
    }

    /** @return array<int, int> open requests in each team member's hands */
    private function openLoad(): array
    {
        return MediaRequest::query()->whereNotNull('assignee_id')
            ->whereIn('status', [\App\Enums\MediaRequestStatus::Assigned, \App\Enums\MediaRequestStatus::InProgress, \App\Enums\MediaRequestStatus::InternalReview,
                \App\Enums\MediaRequestStatus::RequesterReview, \App\Enums\MediaRequestStatus::Production, \App\Enums\MediaRequestStatus::Delivery])
            ->selectRaw('assignee_id, count(*) as n')->groupBy('assignee_id')->pluck('n', 'assignee_id')->map(fn ($n) => (int) $n)->all();
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
        $this->run(fn () => $w->shareDraft(auth()->user(), $this->mediaRequest, $this->link, $this->note, $this->resultUploads), decision: false);
    }

    /** The pre-send checklist the designer ticked (keys of sendChecklist()). */
    public array $sendChecks = [];

    public function sendResult(MediaRequestWorkflow $w): void
    {
        // What the review kept sending back (text, logos, names and numbers, missing sizes) is checked before it goes.
        $missing = array_diff(array_keys($this->sendChecklist()), $this->sendChecks);
        if ($missing !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sendChecks' => __('media.check.all')]);
        }

        $this->run(fn () => $w->sendResult(auth()->user(), $this->mediaRequest, $this->link, $this->note, $this->resultUploads));
        $this->sendChecks = [];
    }

    /**
     * What to check before sending a design or a LinkedIn post's photos: key => label. Photos and video
     * from a shoot go as a link and have no list. These are the corrections the review asked for most.
     *
     * @return array<string, string>
     */
    private function sendChecklist(): array
    {
        $r = $this->mediaRequest;

        return match (true) {
            $r->isLinkedIn() => ['logo' => __('media.check.logo'), 'photos' => __('media.check.photos')],
            $r->service === '001' => [
                'text' => __('media.check.text'),
                'logo' => __('media.check.logo'),
                'facts' => __('media.check.facts'),
                'versions' => __('media.check.versions'),
            ],
            default => [],
        };
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

    // --- LinkedIn posts ---------------------------------------------------

    /** PR's checked or translated text; empty keeps the person's own. */
    public string $postText = '';

    public function approveText(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->approveText(auth()->user(), $this->mediaRequest, $this->postText));
        $this->postText = '';
    }

    public function publish(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->publish(auth()->user(), $this->mediaRequest, $this->link));
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
        $this->run(fn () => $w->nudge(auth()->user(), $this->mediaRequest), __('media.toast.nudge', ['who' => $this->mediaRequest->ownerName()]), decision: false);
    }

    // --- Conversation and files ------------------------------------------

    public function comment(MediaRequestWorkflow $w): void
    {
        $this->run(fn () => $w->comment(auth()->user(), $this->mediaRequest, $this->body), __('media.toast.sent'), decision: false);
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

        $this->run(fn () => $w->attach(auth()->user(), $this->mediaRequest, $this->uploads), __('media.toast.asset'), decision: false);
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
            'load' => $can('assign') || $can('approveMedia') ? $this->openLoad() : [],
            'checklist' => $can('work') ? $this->sendChecklist() : [],
            // The other decisions this person may take now: ability => [icon, label, the question it asks].
            'others' => collect([
                'assign' => ['user', $r->assignee_id ? 'act.reassign' : 'act.assign', null],
                'askInfo' => ['alert', 'act.needInfo', 'side.q.askInfo'],
                'hold' => ['pause', 'act.hold', 'side.q.hold'],
                'reject' => ['x', 'act.reject', 'side.q.reject'],
                'cancel' => ['x', 'act.cancel', 'side.q.cancel'],
            ])->filter(fn ($a, string $ability) => $can($ability)),
            // The media manager may stand in for the team member, but the steps of someone else's work
            // are not put in front of them: they sit under "More actions".
            'doItMyself' => $r->assignee_id === null || $r->assignee_id === $user->employee_id || ! $team->canActAsMediaManager($user),
            'team' => $team,
            'packFiles' => $r->isPack() ? $pack->find($r->pack_files ?? []) : collect(),
            'can' => $can,
            'isOwner' => $can('isOwner'),
            'isRequester' => $user->employee_id === $r->requester_id,
            // The person who asked, when not on the media team: the short four-stage view.
            'simple' => $user->employee_id === $r->requester_id && ! $team->seesArchive($user),
        ]);
    }

    /** Run one step, clear the form, and say where the request went. A message, a file or a reminder is not a decision. */
    private function run(callable $action, ?string $message = null, bool $decision = true): void
    {
        // A message from an earlier try (a wrong link) goes once the next try works.
        $this->resetErrorBag();
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

        // A decision taken in the card on the home screen: the card closes, and the home screen shows what is left.
        if ($decision && $this->embedded) {
            $this->dispatch('media-request-decided');
        }
    }
}
