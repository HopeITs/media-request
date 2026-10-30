<?php

namespace App\Livewire\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaDashboard;
use App\Models\MediaRequest;
use App\Models\User;
use App\Support\MediaRequests\DashboardLayout;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Each person's home screen, phone style: widgets and app shortcuts on a grid.
 *
 * Moving, resizing, pinning, hiding and adding all happen in the browser at
 * once (Alpine + SortableJS); the new layout is then saved here in the
 * background without re-rendering, so nothing jumps or waits on the server.
 * The layout and the private notes are stored per person (media_dashboards),
 * and every stored layout is cleaned by DashboardLayout before use.
 */
class Dashboard extends Component
{
    public string $notes = '';

    #[Url(as: 'month')]
    public string $month = '';

    /** The request open in the panel over the home screen (a request link or notification lands here). */
    #[Url(as: 'open', except: null)]
    public ?int $open = null;

    /** The notifications panel over the home screen (the bell in the top bar opens it). */
    #[Url(as: 'bell', except: false)]
    public bool $bellOpen = false;

    /** Notifications shown: "unread" or "all" (empty: unread when there are any). */
    public string $bellFilter = '';

    /** A calendar day picked with a double click (Y-m-d): new request for it, or its requests. */
    public ?string $pickedDay = null;

    /** How many request groups the notifications list shows; "show more" adds to it. */
    public int $bellShown = 8;

    public function mount(): void
    {
        $this->notes = (string) $this->record()->notes;
        $this->month = $this->validMonth($this->month);
        if ($this->open) {
            $this->openRequest($this->open);
        }
    }

    /**
     * The whole layout as the browser now shows it.
     *
     * @param  array<int, array{key?: string, size?: string, pinned?: bool, hidden?: bool}>  $items
     */
    #[Renderless]
    public function saveLayout(array $items): void
    {
        $this->record()->update([
            'layout' => app(DashboardLayout::class)->clean(auth()->user(), array_slice($items, 0, 60)),
        ]);
    }

    public function resetLayout(): void
    {
        $this->record()->update(['layout' => null]);
    }

    /** Start from one of the ready layouts (then change it as before). Unknown names do nothing. */
    public function applyPreset(string $name): void
    {
        if (! isset(DashboardLayout::PRESETS[$name])) {
            return;
        }

        $this->record()->update(['layout' => app(DashboardLayout::class)->preset(auth()->user(), $name)]);
    }

    #[Renderless]
    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:10000']]);
        $this->record()->update(['notes' => $this->notes]);
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->validMonth($this->month).'-01')->addMonthsNoOverflow($delta < 0 ? -1 : 1)->format('Y-m');
    }

    /** Open a request in the panel; only one the person may see. */
    public function openRequest(int $id): void
    {
        $request = MediaRequest::find($id);
        $this->open = $request && Gate::allows('view', $request) ? $id : null;
    }

    public function closeRequest(): void
    {
        $this->open = null;
    }

    public function toggleBell(): void
    {
        $this->bellOpen = ! $this->bellOpen;
    }

    /** A notification from the card: mark it read and open its request here. */
    public function openNotification(string $id): void
    {
        $n = auth()->user()->notifications()->whereKey($id)->first();
        if (! $n) {
            return;
        }

        $n->markAsRead();
        $this->bellOpen = false;
        if ($requestId = $n->data['media_request_id'] ?? null) {
            $this->openRequest((int) $requestId);
        }
    }

    /** A request's row in the notifications: mark all its updates read and open it here. */
    public function openRequestNotes(int $requestId): void
    {
        auth()->user()->unreadNotifications()->where('data->media_request_id', $requestId)->update(['read_at' => now()]);
        $this->bellOpen = false;
        $this->openRequest($requestId);
    }

    public function pickDay(string $date): void
    {
        $this->pickedDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : null;
    }

    public function closeDay(): void
    {
        $this->pickedDay = null;
    }

    public function bellShow(string $filter): void
    {
        $this->bellFilter = in_array($filter, ['unread', 'all'], true) ? $filter : '';
        $this->bellShown = 8;
    }

    public function bellMore(): void
    {
        $this->bellShown = min($this->bellShown + 8, 80);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function thisMonth(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function render()
    {
        $user = auth()->user();
        $cards = app(DashboardLayout::class);
        $layout = $cards->clean($user, $this->record()->layout);
        $keys = array_column($layout, 'key');

        $view = [
            'layout' => $layout,
            'cards' => $cards,
            // Always counted: the greeting says how many wait on you, even with the card hidden.
            'waiting' => $waiting = $this->waiting($user),
            // Decisions waiting on this person (approve, check, review, answer), oldest first, for the cards at the top.
            'decisions' => $waiting->whereIn('status', [S::ProgramApproval, S::MediaApproval, S::InternalReview, S::RequesterReview, S::NeedsInfo])
                ->sortBy(fn (MediaRequest $r) => $r->status_changed_at ?? $r->created_at)->values(),
            'calendar' => in_array('calendar', $keys, true) ? $this->calendar($user) : [],
            'team' => in_array('team', $keys, true) ? $this->teamLoad() : collect(),
            'mine' => in_array('mine', $keys, true) ? $this->mine($user) : collect(),
            'flow' => in_array('flow', $keys, true) ? app(\App\Support\MediaRequests\RequestFlow::class)->for($user) : null,
            'bell' => in_array('bell', $keys, true) || $this->bellOpen ? $this->notificationGroups($user) : null,
            'showcase' => in_array('showcase', $keys, true) ? $this->showcase($user) : [],
            'late' => $this->late($user),
            'openRequest' => $this->open ? MediaRequest::find($this->open) : null,
            'dayRequests' => $this->pickedDay ? MediaRequest::query()->visibleTo($user)->whereDate('needed_by', $this->pickedDay)
                ->whereNotIn('status', [S::Rejected, S::Cancelled])->with(['assignee'])->orderBy('status')->get() : collect(),
        ];

        // Cards with nothing in them right now go last, as one closed line (the grid does it; see home-screen.js).
        $view['empty'] = array_keys(array_filter([
            'late' => ! $view['late'],
            'decide' => $view['decisions']->isEmpty(),
            'waiting' => $view['waiting']->isEmpty(),
            'mine' => in_array('mine', $keys, true) && $view['mine']->isEmpty(),
            'bell' => $view['bell'] !== null && ($view['bell']['total'] ?? 0) === 0,
            'showcase' => in_array('showcase', $keys, true) && $view['showcase'] === [],
        ]));

        // Hidden ones too: one added back from the gallery is drawn at once, without a reload.
        $view['apps'] = $this->appCards($user, array_values(array_filter($keys, fn ($k) => $cards->isApp($k))), $view);

        return view('livewire.media-requests.dashboard', $view);
    }

    // ------------------------------------------------------------------

    /**
     * The latest approved designs, for everyone's slideshow: delivered requests only (approved by
     * the media manager and the requester's side), one picture each (the newest image the team
     * sent as the result; never a draft, SVG or open file), newest first. Whether the person may
     * also open the request and its tracking is decided per design (MediaRequestPolicy::view).
     *
     * @return array<int, array{id: int, src: string, name: string, program: string, by: ?string, on: ?string, canOpen: bool}>
     */
    private function showcase(User $user): array
    {
        $locale = app()->getLocale();
        $images = \App\Http\Controllers\MediaRequestFileController::SHOWCASE_TYPES;
        $isResult = fn ($m) => ($m->getCustomProperty('kind') ?? 'result') === 'result' && in_array($m->mime_type, $images, true);

        return MediaRequest::query()->where('status', S::Delivered)
            ->whereHas('media', fn ($q) => $q->where('collection_name', 'results')->whereIn('mime_type', $images))
            ->with(['media', 'program', 'city', 'assignee'])
            ->orderByDesc('delivered_at')->limit(12)->get()
            ->map(function (MediaRequest $r) use ($isResult, $locale, $user): ?array {
                $pic = $r->getMedia('results')->filter($isResult)->sortByDesc('id')->first();

                return $pic ? [
                    'id' => $r->id,
                    'canOpen' => Gate::forUser($user)->allows('view', $r),
                    'src' => route('media-requests.showcase', $pic),
                    'name' => $r->shortName(),
                    'program' => $r->program ? trim($r->program->code.' '.$r->program->slug) : '',
                    'by' => $r->assignee?->nameForLocale($locale),
                    'on' => $r->delivered_at?->format('d-m-Y'),
                ] : null;
            })->filter()->values()->all();
    }

    /**
     * What a shortcut shows once it is made big enough to be a card (see app-card.blade.php):
     * one number and a few short rows, simplified from its page. Only for the shortcuts on
     * this person's screen; each query is small and capped.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{num?: int, unit?: string, rows: array<int, array{t: string, s?: string, href?: string}>, empty?: string}>
     */
    private function appCards(User $user, array $keys, array $view): array
    {
        $locale = app()->getLocale();
        $show = fn (MediaRequest $r) => route('media-requests.show', $r);
        $req = fn (MediaRequest $r, ?string $sub = null) => ['t' => $r->shortName(), 's' => $sub ?? S::label($r->status, $r->type), 'href' => $show($r)];
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = match ($key) {
                'app.my' => (function () use ($user, $req) {
                    $mine = $this->mine($user);

                    return ['num' => $mine->filter->isOpen()->count(), 'unit' => __('media.dash.ac.open'),
                        'rows' => $mine->take(4)->map(fn ($r) => $req($r))->all(), 'empty' => __('media.dash.ac.noMine')];
                })(),
                'app.approvals' => (function () use ($view, $req, $locale) {
                    $mine = $view['decisions']->whereIn('status', [S::ProgramApproval, S::MediaApproval]);

                    return ['num' => $mine->count(), 'unit' => __('media.dash.ac.toApprove'),
                        'rows' => $mine->take(4)->map(fn ($r) => $req($r, $r->requester?->nameForLocale($locale)))->values()->all(), 'empty' => __('media.dash.ac.noApprove')];
                })(),
                'app.board' => (function () {
                    $n = MediaRequest::query()->whereIn('status', [S::ToClaim, S::Assigned, S::InProgress, S::InternalReview])
                        ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

                    return ['num' => (int) ($n[S::ToClaim] ?? 0), 'unit' => S::label(S::ToClaim), 'rows' => [
                        ['t' => S::label(S::InProgress), 's' => (string) (($n[S::Assigned] ?? 0) + ($n[S::InProgress] ?? 0))],
                        ['t' => S::label(S::InternalReview), 's' => (string) ($n[S::InternalReview] ?? 0)],
                    ]];
                })(),
                'app.drive' => [
                    'rows' => MediaRequest::query()->visibleTo($user)->where('status', S::Delivered)->orderByDesc('delivered_at')->limit(4)->get()
                        ->map(fn ($r) => $req($r, $r->delivered_at?->format('d-m-Y')))->all(),
                    'empty' => __('media.dash.ac.noFiles'),
                ],
                'app.stats' => (function () {
                    $month = now()->startOfMonth();

                    return ['num' => MediaRequest::query()->where('status', S::Delivered)->where('delivered_at', '>=', $month)->count(), 'unit' => __('media.dash.ac.deliveredMonth'), 'rows' => [
                        ['t' => __('media.dash.ac.receivedMonth'), 's' => (string) MediaRequest::query()->whereNull('imported_from')->where('created_at', '>=', $month)->count()],
                        ['t' => __('media.dash.ac.openNow'), 's' => (string) MediaRequest::query()->open()->count()],
                    ]];
                })(),
                'app.log' => ['rows' => \Spatie\Activitylog\Models\Activity::query()->where('log_name', MediaRequest::LOG_NAME)
                    ->with(['causer.profile', 'subject'])->latest()->limit(4)->get()
                    ->map(fn ($a) => ['t' => __('media.lg.'.$a->description).($a->subject instanceof MediaRequest ? ' · '.$a->subject->shortName() : ''),
                        's' => trim(($a->causer?->profile?->nameForLocale($locale) ?? '').' · '.$a->created_at->diffForHumans(), ' ·')])->all()],
                'app.assistant' => (function () {
                    $missed = \App\Models\MediaAssistantLog::query()->where('understood', false)->whereNull('lesson_id');

                    return ['num' => (clone $missed)->count(), 'unit' => __('media.dash.ac.toTeach'),
                        'rows' => $missed->latest()->limit(3)->pluck('text')->map(fn ($t) => ['t' => \Illuminate\Support\Str::limit($t, 70)])->all(), 'empty' => __('media.dash.ac.noTeach')];
                })(),
                'app.bell' => ['num' => $user->unreadNotifications()->count(), 'unit' => __('media.dash.ac.unread'),
                    'rows' => $user->notifications()->latest()->limit(4)->get()
                        ->map(fn ($n) => ['t' => $n->data['body'][$locale] ?? '', 's' => $n->created_at->diffForHumans()])->all(),
                    'empty' => __('media.bell.none')],
                'app.people' => ['num' => \App\Models\Employee::query()->count(), 'unit' => __('media.dash.ac.people'),
                    'rows' => [['t' => __('media.dash.ac.team'), 's' => (string) app(MediaTeam::class)->members()->count()]]],
                'app.generator' => ['rows' => collect(config('media-requests.generator.tools', []))
                    ->map(fn ($tool, $k) => ['t' => __('media.gen.tool.'.$k), 'href' => app(\App\Support\MediaRequests\HcGenerator::class)->url($tool['path'])])->values()->all()],
                default => ['rows' => []],   // app.new and app.pack draw their own card
            };
        }

        return $out;
    }

    /** Open requests that wait on this person, most urgent first. */
    private function waiting(User $user): Collection
    {
        return MediaRequest::query()->open()->relevantTo($user)
            ->with(['requester', 'assignee', 'program', 'city'])
            ->orderBy('needed_by')->limit(100)->get()
            ->filter(fn (MediaRequest $r) => Gate::allows('isOwner', $r))
            ->values();
    }

    /**
     * The notifications, one row per request (its latest update and how many
     * others), unread only when there are any, newest first, in sections:
     * today, this week, earlier. Updates not about a request stay one row each.
     *
     * @return array{filter: string, unread: int, total: int, more: bool, sections: array<string, Collection>}
     */
    private function notificationGroups(User $user): array
    {
        $unread = $user->unreadNotifications()->count();
        $filter = $this->bellFilter ?: ($unread ? 'unread' : 'all');

        $groups = ($filter === 'unread' ? $user->unreadNotifications() : $user->notifications())->latest()->limit(300)->get()
            ->groupBy(fn ($n) => isset($n->data['media_request_id']) ? 'r'.$n->data['media_request_id'] : 'n'.$n->id)
            ->map(fn (Collection $g) => ['latest' => $g->first(), 'count' => $g->count(), 'unread' => $g->whereNull('read_at')->count(),
                'request' => $g->first()->data['media_request_id'] ?? null])
            ->values();

        $shown = $groups->take($this->bellShown);
        $section = fn ($g) => $g['latest']->created_at->isToday() ? 'today' : ($g['latest']->created_at->gt(now()->subDays(7)) ? 'week' : 'earlier');

        return [
            'filter' => $filter,
            'unread' => $unread,
            'total' => $groups->count(),
            'more' => $groups->count() > $shown->count(),
            'sections' => $shown->groupBy($section)->all(),
        ];
    }

    /**
     * Past their needed-by date: for the media manager the whole team's, for a team member their own.
     * Paused requests (on hold) are left out. Oldest deadline first.
     *
     * @return array{scope: string, items: Collection}|null
     */
    private function late(User $user): ?array
    {
        $team = app(MediaTeam::class);
        $manager = $team->canActAsMediaManager($user);
        if (! $manager && ! $team->isMember($user->profile)) {
            return null;
        }

        $items = MediaRequest::query()
            ->whereIn('status', [S::ToClaim, S::Assigned, S::InProgress, S::InternalReview, S::Production, S::Delivery])
            ->whereNotNull('needed_by')->whereDate('needed_by', '<', today())
            ->when(! $manager, fn ($q) => $q->where('assignee_id', $user->employee_id))
            ->with('assignee')->orderBy('needed_by')->limit(50)->get();

        return $items->isEmpty() ? null : ['scope' => $manager ? 'team' : 'mine', 'items' => $items];
    }

    /** The person's own requests: open ones first, then the latest finished. */
    private function mine(User $user): Collection
    {
        return MediaRequest::query()->where('requester_id', $user->employee_id)
            ->with(['assignee', 'program', 'city'])
            ->latest()->limit(40)->get()
            ->sortBy(fn (MediaRequest $r) => $r->isOpen() ? 0 : 1)
            ->values();
    }

    /** Weeks of the shown month (Saturday first), each day with the requests needed that day. */
    private function calendar(User $user): array
    {
        $first = Carbon::createFromFormat('Y-m-d', $this->validMonth($this->month).'-01')->startOfDay();
        $start = $first->copy()->startOfWeek(Carbon::SATURDAY);
        $end = $first->copy()->endOfMonth()->endOfWeek(Carbon::FRIDAY);

        $byDay = MediaRequest::query()->relevantTo($user)
            ->whereNotIn('status', [S::Rejected, S::Cancelled])
            ->whereBetween('needed_by', [$start->toDateString(), $end->toDateString()])
            ->orderBy('needed_by')->get()
            ->groupBy(fn (MediaRequest $r) => $r->needed_by->toDateString());

        $weeks = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $weeks[intdiv($start->diffInDays($day), 7)][] = [
                'date' => $day->copy(),
                'in' => $day->month === $first->month,
                'today' => $day->isToday(),
                'items' => $byDay->get($day->toDateString(), collect()),
            ];
        }

        return ['first' => $first, 'weeks' => $weeks];
    }

    /**
     * What each team member is doing now, for the media manager and the team:
     * the request they are working on, the ones they took but have not started,
     * and the others they hold (in review, at the printer, waiting for an answer).
     * First row: requests nobody has taken yet.
     */
    private function teamLoad(): Collection
    {
        $held = [S::Assigned, S::InProgress, S::InternalReview, S::RequesterReview, S::Production, S::Delivery, S::NeedsInfo];
        $byPerson = MediaRequest::query()->whereIn('status', $held)->whereNotNull('assignee_id')
            ->orderByDesc('status_changed_at')->get()->groupBy('assignee_id');
        $toClaim = MediaRequest::query()->where('status', S::ToClaim)->orderBy('needed_by')->get();
        // Delivered early in the last 30 days, per person: their badge on the card.
        $recent = MediaRequest::query()->where('status', S::Delivered)->where('delivered_at', '>=', now()->subDays(30))->whereNotNull('assignee_id')->get();
        $boost = $recent->filter(fn (MediaRequest $r) => $r->sameDay())->countBy('assignee_id');
        $early = $recent->filter(fn (MediaRequest $r) => ! $r->sameDay() && $r->deliveryTiming() === 'early')->countBy('assignee_id');

        return app(MediaTeam::class)->members()
            ->map(function ($e) use ($byPerson, $early, $boost) {
                $mine = $byPerson->get($e->id, collect());

                return [
                    'employee' => $e,
                    'now' => $mine->where('status', S::InProgress)->values(),
                    'taken' => $mine->where('status', S::Assigned)->values(),
                    'other' => $mine->whereNotIn('status', [S::InProgress, S::Assigned])->values(),
                    'n' => $mine->count(),
                    'early' => (int) ($early[$e->id] ?? 0),
                    'boost' => (int) ($boost[$e->id] ?? 0),
                ];
            })
            // Busiest first; free people last.
            ->sortByDesc(fn ($row) => [$row['now']->count(), $row['n']])->values()
            ->prepend(['employee' => null, 'now' => collect(), 'taken' => $toClaim, 'other' => collect(), 'n' => $toClaim->count(), 'early' => 0, 'boost' => 0]);
    }
    private function record(): MediaDashboard
    {
        return MediaDashboard::firstOrCreate(['user_id' => auth()->id()]);
    }

    private function validMonth(string $month): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now()->format('Y-m');
    }
}
