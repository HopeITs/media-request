@php
    use App\Enums\MediaRequestStatus as S;

    $locale = app()->getLocale();
    $steps = $r->steps();
    $current = $r->currentStep();
    $name = fn ($employee) => $employee?->nameForLocale($locale) ?? '—';
    $initials = fn ($employee) => $employee ? mb_strtoupper(mb_substr($employee->first_name_en, 0, 1).mb_substr((string) $employee->last_name_en, 0, 1)) : '?';
    $svcIcon = ['001' => 'pen', '002' => 'camera', '003' => 'video', '004' => 'send', '000' => 'box'][$r->service] ?? 'file';
    $stepIcon = ['submitted' => 'send', 'pm' => 'shield', 'mm' => 'shield', 'claim' => 'user', 'progress' => 'pen', 'int' => 'eye', 'req' => 'eye', 'production' => 'printer', 'delivery' => 'box', 'text' => 'edit', 'publish' => 'send'];
    $evIcon = ['submitted' => 'send', 'edited' => 'edit', 'pmApproved' => 'shield', 'mmApproved' => 'shield', 'assigned' => 'user', 'claimed' => 'user',
        'started' => 'play', 'draft' => 'eye', 'sentInt' => 'send', 'sentReq' => 'send', 'intOk' => 'check', 'intBack' => 'back', 'reqOk' => 'check',
        'changes' => 'edit', 'ready' => 'box', 'delivered' => 'check', 'packAsked' => 'box', 'packDone' => 'check', 'info' => 'alert', 'replied' => 'msg',
        'rejected' => 'x', 'hold' => 'pause', 'resumed' => 'play', 'cancelled' => 'x', 'nudged' => 'bell', 'asset' => 'clip', 'comment' => 'msg'];
    $me = auth()->user()->employee_id;
    $sideActions = Gate::any(['askInfo', 'reject', 'hold', 'cancel', 'assign'], $r);
@endphp

<div>
    @unless ($embedded)
    <a href="{{ route('media-requests.index') }}" wire:navigate class="crumb"><x-ic n="back" class="flip" />{{ __('media.nav.my') }}</a>
    @endunless

    {{-- Title --}}
    <header class="dh">
        <div class="row">
            <span class="tag soft num ltr">{{ $r->reference }}</span>
            <x-media.status :r="$r" />
            @if ($r->wantsPrint())<span class="tag soft"><x-ic n="printer" />{{ __('media.tag.withPrint') }} · <span class="num">{{ $r->answer('printQty') }}</span></span>@endif
            @if ($r->needsQuote() && $r->isOpen())<span class="tag rush"><x-ic n="alert" />{{ __('media.tag.quote') }}</span>@endif
            @if ($specialty)<span class="tag soft" title="{{ __('media.spec.title') }}"><x-ic :n="config('media-requests.specialties.'.$specialty.'.icon')" />{{ __('media.spec.'.$specialty) }}</span>@endif
            @if ($r->priority)<span class="tag soft">{{ __('media.prio') }}: {{ __('media.prio.'.$r->priority) }}</span>@endif
            @if ($r->rush_reason && $r->isOpen())<span class="tag rush"><x-ic n="flame" />{{ __('media.tag.rush') }}</span>@endif
        </div>
        <h1 style="text-align:start"><x-media.name :r="$r" :kind="false" /></h1>
        <div class="meta">
            <span><x-ic :n="$svcIcon" />{{ __('media.svc.'.$catalog->serviceKey($r->service)) }}@unless ($r->isPack()) · {{ __('media.type.'.$r->type) }}@endunless</span>
            <span><x-ic n="user" />{{ $name($r->requester) }}</span>
            <span><x-ic n="cal" /><span class="num">{{ $r->created_at->format('d-m-Y') }}</span></span>
            @can('update', $r)
                <button type="button" class="btn-q" x-on:click="$dispatch('media-wizard-open', { id: {{ $r->id }} })"><x-ic n="edit" />{{ __('media.ew.btn') }}</button>
            @endcan
        </div>
    </header>

    {{-- The requester's first look at their delivered request: the same short celebration, once per request on this device. --}}
    @if ($isRequester && $r->status === 'delivered')
        <div hidden x-data x-init="try { if (! localStorage.getItem('mr-cel-{{ $r->id }}')) { localStorage.setItem('mr-cel-{{ $r->id }}', '1'); setTimeout(() => mrCelebrate(@js(__('media.cel.yours'))), 300) } } catch (e) {}"></div>
    @endif

    {{-- For their program manager: who has it and what they are doing, in one sentence (the requester has it in their own card below). --}}
    @if (! $simple && ($isRequester || app(\App\Support\MediaRequests\MediaTeam::class)->managesProgram(auth()->user()->profile, $r->program_id)))
        <x-media.progress :r="$r" :for-requester="$isRequester" class="progress lg" />
    @endif

    {{-- Printing with an unknown budget: the team sends a quote first; the requester knows it is coming. --}}
    @if ($r->needsQuote() && $r->isOpen())
        <div class="banner soft" style="margin-bottom:18px"><x-ic n="alert" /><span>{{ $isRequester ? __('media.quote.mine') : __('media.quote.banner') }}</span></div>
    @endif

    {{-- A shoot: which team covers the place, or that a service agreement does. --}}
    @if ($team->onSite($r) && $r->city && $r->isOpen())
        @php($teams = \App\Models\City::whereIn('slug', $team->areaTeams($r))->get()->sortBy(fn ($c) => array_search($c->slug, $team->areaTeams($r)))->map(fn ($c) => $locale === 'ar' ? $c->name_ar : $c->name_en)->implode(' / '))
        @php($cityName = $locale === 'ar' ? $r->city->name_ar : $r->city->name_en)
        @if ($team->underAgreement($r))
            <div class="banner soft" style="margin-bottom:14px"><x-ic n="alert" /><span>{{ __('media.cov.agreement', ['city' => $cityName]) }}</span></div>
        @elseif ($members->isNotEmpty() && $members->doesntContain(fn ($m) => $team->coversArea($m, $r)))
            <div class="banner soft" style="margin-bottom:14px"><x-ic n="alert" /><span>{{ __('media.cov.none', ['city' => $cityName, 'teams' => $teams]) }}</span></div>
        @elseif ($members->isNotEmpty() || $isRequester)
            <p class="sub" style="margin:-6px 0 14px;display:flex;gap:6px;align-items:center"><x-ic n="camera" />{{ __('media.cov.note', ['city' => $cityName, 'teams' => $teams]) }}</p>
        @endif
    @endif

    {{-- Tracker: every stage and who holds it now --}}
    @if (in_array($r->status, [S::Rejected, S::Cancelled], true))
        <div class="banner stop" style="margin-bottom:20px"><x-ic n="x" /><div><b>{{ S::label($r->status) }}</b>
            @php($why = $history->first(fn ($a) => in_array($a->description, ['rejected', 'cancelled'], true))?->properties->get('note'))
            @if ($why)<p class="sub">{{ $why }}</p>@endif
        </div></div>
    @elseif ($simple)
        {{-- The person who asked: one card that says where it is, what comes next, and when. --}}
        <section class="card" style="margin-bottom:20px" aria-labelledby="trk">
            <h2 id="trk" class="vh">{{ __('media.lc.title') }}</h2>
            <x-media.journey :r="$r">
                @can('nudge', $r)
                    <button type="button" class="btn btn-o btn-sm" style="justify-self:start" wire:click="nudge"><x-ic n="bell" />{{ __('media.nudge', ['who' => $r->ownerName()]) }}</button>
                @endcan
            </x-media.journey>
        </section>
    @else
        <section class="card" style="margin-bottom:20px" aria-labelledby="trk">
            <h2 id="trk" class="vh">{{ __('media.lc.title') }}</h2>
            <div class="trk-mini" aria-hidden="true">
                <span class="sub num">{{ __('media.lc.step', ['n' => min($current + 1, count($steps)), 'of' => count($steps)]) }}</span>
                <div class="segs">
                    @foreach ($steps as $i => $step)<i data-s="{{ $i < $current ? 'done' : ($i === $current ? 'cur' : 'todo') }}"></i>@endforeach
                </div>
            </div>
            <ol class="stepper">
                @foreach ($steps as $i => $step)
                    @php($s = $i < $current ? 'done' : ($i === $current ? 'cur' : 'todo'))
                    <li data-s="{{ $s }}" @if ($s === 'cur') aria-current="step" @endif>
                        <span class="dot">@if ($s === 'done')<x-ic n="check" />@elseif ($s === 'cur')<x-ic :n="$stepIcon[$step] ?? 'clock'" />@else{{ $i + 1 }}@endif</span>
                        <span>{{ __('media.sp.'.$step) }}@if ($s === 'cur' && $r->owner())<span class="ow" style="display:block">{{ $r->ownerName() }}</span>@endif</span>
                    </li>
                @endforeach
            </ol>
            <div class="curstat">
                <div>
                    <span class="lab">{{ __('media.lc.status') }}</span>
                    @if ($r->isOpen() && $r->owner())
                        <span>{{ __('media.meta.with', ['who' => '']) }}<b class="owner">{{ $r->ownerName() }}</b>
                            @if ($r->status_changed_at)<span class="sub"> · {{ __('media.tl.for', ['d' => $r->status_changed_at->diffForHumans(null, true)]) }}</span>@endif</span>
                    @elseif ($r->status === S::Delivered)
                        <b>{{ __($r->isPack() ? 'media.act.packDone' : 'media.act.delivered') }}</b>
                    @endif
                </div>
                <div style="text-align:end">
                    <span class="lab">{{ __('media.lc.due') }}</span>
                    <b class="num">{{ $r->needed_by?->format('d-m-Y') ?? '—' }}</b>
                </div>
                @can('nudge', $r)
                    <button type="button" class="btn btn-o btn-sm" wire:click="nudge"><x-ic n="bell" />{{ __('media.nudge', ['who' => $r->ownerName()]) }}</button>
                @endcan
            </div>
        </section>
    @endif

    <div class="dgrid">
        {{-- Details, conversation, history --}}
        <div class="stack" x-data="{ tab: 'overview' }">
            <div class="tabs" role="tablist">
                @foreach (['overview' => ['tab.overview', 'list'], 'conversation' => ['tab.conversation', 'msg'], 'history' => ['tab.activity', 'clock']] as $key => [$label, $icon])
                    <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'">
                        <x-ic :n="$icon" />{{ __('media.'.$label) }}
                        @if ($key === 'conversation' && $r->comments->isNotEmpty())<span class="cnt num">{{ $r->comments->count() }}</span>@endif
                    </button>
                @endforeach
            </div>

            <div x-show="tab === 'overview'" class="stack">
                <section class="card">
                    <h2>{{ __('media.d.summary') }}</h2>
                    <dl class="kv">
                        <div><dt>{{ __('media.d.program') }}</dt><dd class="ltr" style="text-align:start">{{ $r->program ? trim($r->program->code.' '.$r->program->slug) : '—' }}</dd></div>
                        <div><dt>{{ __('media.d.city') }}</dt><dd>{{ $r->city ? ($locale === 'ar' ? $r->city->name_ar : $r->city->name_en) : '—' }}</dd></div>
                        <div><dt>{{ __('media.d.deadline') }}</dt><dd class="num">{{ $r->needed_by?->format('d-m-Y') ?? '—' }}</dd></div>
                        @if ($r->planned_for)<div><dt>{{ __('media.d.planned') }}</dt><dd class="num">{{ $r->planned_for->format('d-m-Y') }}</dd></div>@endif
                        <div><dt>{{ __('media.d.officer') }}</dt><dd>{{ $r->assignee ? $name($r->assignee) : __('media.d.unassigned') }}</dd></div>
                        @if ($r->revisions)<div><dt>{{ __('media.d.revisions') }}</dt><dd class="num">{{ $r->revisions }}</dd></div>@endif
                    </dl>
                    @unless ($r->isPack())
                        <div class="act-sep"></div>
                        <dl class="kv">
                            <div><dt>{{ __('media.path.pm') }}</dt><dd>{{ ! $r->needs_program_approval ? __('media.ap.na') : ($r->program_approved_at ? __('media.ap.approved').' · '.$name($r->programApprover) : __('media.ap.pending')) }}</dd></div>
                            <div><dt>{{ __('media.path.mm') }}</dt><dd>{{ $r->media_approved_at ? __('media.ap.approved').' · '.$name($r->mediaApprover) : __('media.ap.pending') }}</dd></div>
                        </dl>
                    @endunless
                    @if ($r->rush_reason)
                        <div class="act-sep"></div>
                        <dl class="kv"><div><dt>{{ __('media.d.rushReason') }}</dt><dd>{{ $r->rush_reason }}</dd></div></dl>
                    @endif
                </section>

                @if ($summary !== [] || $r->description)
                    <section class="card">
                        <h2>{{ __('media.d.details') }}</h2>
                        @if ($summary !== [])
                            <dl class="dl">
                                @foreach ($summary as $row)<dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd>@endforeach
                            </dl>
                        @endif
                        @if ($r->description)
                            @if ($summary !== [])<div class="act-sep"></div>@endif
                            <p style="white-space:pre-line">{{ $r->description }}</p>
                        @endif
                    </section>
                @endif

                <section class="card">
                    <h2>{{ __('media.d.files') }}</h2>
                    <x-media.results :r="$r" />
                    <ul class="files">
                        {{-- The archive (open files) is the media team's only. --}}
                        @foreach (array_filter(['draft' => $r->draft_link, 'result' => $r->result_link, 'archive' => $team->seesArchive(auth()->user()) ? $r->archive_link : null]) as $kind => $href)
                            <li><a href="{{ $href }}" target="_blank" rel="noopener noreferrer"><x-ic n="link" />{{ __('media.link.'.$kind) }}</a></li>
                        @endforeach
                        @foreach ($packFiles as $file)
                            {{-- Kept in the app (added or replaced by the media manager): the team downloads it, the requester once delivered. --}}
                            @if ($file['id'] && ($team->isMember(auth()->user()->profile) || $team->canActAsMediaManager(auth()->user()) || $r->status === \App\Enums\MediaRequestStatus::Delivered))
                                <li><a href="{{ route('media-requests.pack.file', ['file' => $file['id'], 'r' => $r->id]) }}"><x-ic n="download" /><span class="ltr">{{ $file['path'] }}</span></a></li>
                            @else
                                <li><span><x-ic n="box" /><span class="ltr">{{ $file['path'] }}</span></span></li>
                            @endif
                        @endforeach
                        @foreach ($r->getMedia('attachments') as $media)
                            <li><a href="{{ route('media-requests.files', $media) }}"><x-ic n="download" /><span class="ltr">{{ $media->file_name }}</span></a></li>
                        @endforeach
                    </ul>
                    @if (! $r->draft_link && ! $r->result_link && ! $r->archive_link && $packFiles->isEmpty() && $r->getMedia('attachments')->isEmpty() && $r->getMedia('results')->isEmpty())
                        <p class="sub">{{ __('media.att.empty') }}</p>
                    @endif
                    @if ($r->archive_name && $team->seesArchive(auth()->user()))
                        <p class="sub" style="margin-top:10px">{{ __('media.d.archName') }}: <code class="ltr">{{ $r->archive_name }}</code></p>
                    @endif
                    @can('attach', $r)
                        <form wire:submit="attachFiles" style="margin-top:14px;display:grid;gap:8px">
                            <label class="drop">
                                <span class="di"><x-ic n="upload" /></span>
                                <b>{{ __('media.as.drop') }}</b>
                                <span class="sub">{{ __('media.as.max') }}</span>
                                <input type="file" multiple wire:model="uploads" class="vh">
                            </label>
                            @if ($uploads)
                                <button type="submit" class="btn btn-o"><x-ic n="upload" />{{ __('media.att.file') }} ({{ count($uploads) }})</button>
                            @endif
                            @error('uploads')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                            @error('uploads.*')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </form>
                    @endcan
                </section>
            </div>

            <section x-show="tab === 'conversation'" x-cloak class="card">
                @if ($r->comments->isEmpty())
                    <p class="sub" style="margin-bottom:14px">{{ __('media.cm.none') }}</p>
                @else
                    <ol class="thread">
                        @foreach ($r->comments as $c)
                            <li @class(['ev', 'mine' => $c->employee_id === $me])>
                                <span class="av sm soft">{{ $initials($c->author) }}</span>
                                <div><span class="who">{{ $name($c->author) }}</span><time>{{ $c->created_at->format('d-m-Y H:i') }}</time>
                                    <div class="note">{{ $c->body }}</div></div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @can('comment', $r)
                    <form wire:submit="comment" class="composer">
                        <label for="mr-body" class="vh">{{ __('media.cm.label') }}</label>
                        <div class="row">
                            <textarea id="mr-body" class="inp" rows="2" wire:model="body" placeholder="{{ __('media.cm.ph') }}" x-data="mrTypewriter(@js(__('media.ex.comment')))"></textarea>
                            <button type="submit" class="btn btn-p" aria-label="{{ __('media.cm.send') }}"><x-ic n="send" class="flip" /></button>
                        </div>
                        <button type="button" class="btn-q proofbtn" x-on:click="$dispatch('media-proofread-open', { text: document.getElementById('mr-body').value, target: 'body' })"><x-ic n="check" />{{ __('media.proof.check') }}</button>
                        @error('body')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                    </form>
                @endcan
            </section>

            <section x-show="tab === 'history'" x-cloak class="card">
                <ol class="thread" style="margin:0">
                    @foreach ($history as $a)
                        <li class="ev sys">
                            <span class="av sm"><x-ic :n="$evIcon[$a->description] ?? 'clock'" /></span>
                            <div>
                                <span><span class="who">{{ $a->causer?->profile?->nameForLocale($locale) ?? '—' }}</span> {{ __('media.ev.'.$a->description, \App\Notifications\MediaRequestUpdated::localizeParams(array_intersect_key($a->properties->all(), ['prio' => 1, 'n' => 1]) + ['to' => $a->properties->get('assignee') ?? $a->properties->get('target'), 'role' => $a->properties->get('target') ? null : $a->properties->get('owner')], $locale)) }}</span>
                                <time>{{ $a->created_at->format('d-m-Y H:i') }}</time>
                                @if ($a->properties->get('note'))<div class="note">{{ $a->properties->get('note') }}</div>@endif
                                @if ($a->properties->get('link'))<a class="btn-q" style="padding:0;min-height:32px" href="{{ $a->properties->get('link') }}" target="_blank" rel="noopener noreferrer"><x-ic n="link" />{{ __('media.link.open') }}</a>@endif
                                @if ($changes = $a->properties->get('changes'))<p class="sub">{{ \App\Livewire\MediaRequests\Log::details($a, $catalog) }}</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        {{-- What this person can do now --}}
        <aside>
            <section @class(['card act', 'mine' => $isOwner]) aria-labelledby="mr-act">
                {{-- The person who asked already reads where it is above; here only what they can do. --}}
                <div class="act-h" @if ($simple && ! $isOwner) hidden @endif>
                    <x-ic :n="$isOwner ? 'arrow' : ($r->isOpen() ? 'clock' : 'check')" @class(['flip' => $isOwner]) />
                    <h2 id="mr-act">{{ $isOwner ? __('media.act.title') : ($r->isOpen() ? __('media.act.status') : __('media.act.closedTitle')) }}</h2>
                </div>

                @if (! $isOwner && $r->isOpen() && ! $simple)
                    <p class="act-p">{{ __('media.act.wait', ['who' => $r->ownerName()]) }}</p>
                @endif

                <div class="stack" style="gap:12px">
                    @can('approveProgram', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.pm') }}</p>
                        <button type="button" class="btn btn-p btn-w" wire:click="approveProgram" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.act.approve') }}</button>
                    @endcan

                    @can('approveMedia', $r)
                        {{-- One decision: approve and give it to the person already chosen (the best fit, least busy).
                             The person can be changed; priority and date are set already and wait behind "Priority and date". --}}
                        <form wire:submit="approveMedia" class="stack" style="gap:12px" x-data="{ more: @js($errors->has('plannedFor')) }">
                            <div class="field" style="margin:0">
                                <label for="mr-assignee">{{ __('media.fm.giveTo') }}</label>
                                <select id="mr-assignee" class="inp" wire:model="assigneeId">
                                    <option value="">{{ __('media.fm.letClaim') }}</option>
                                    @include('livewire.media-requests.partials.assignee-options')
                                </select>
                                @error('assigneeId')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn btn-p btn-w" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.act.mmApprove') }}</button>
                            <button type="button" class="btn-q prio-date" style="padding:0" x-on:click="more = ! more" :aria-expanded="more">
                                <span><x-ic n="cal" />{{ __('media.fm.prioDate') }}</span>
                                <small>{{ __('media.prio.'.$priority) }} · <span class="num">{{ $plannedFor ? \Illuminate\Support\Carbon::parse($plannedFor)->format('d-m-Y') : '—' }}</span></small>
                            </button>
                            <div x-show="more" x-cloak class="stack" style="gap:12px">
                                <fieldset class="field" style="margin:0">
                                    <legend>{{ __('media.fm.prio') }}</legend>
                                    <div class="choices">
                                        @foreach (config('media-requests.priorities') as $p)
                                            <label class="choice"><input type="radio" value="{{ $p }}" wire:model.live="priority" name="priority"><span><x-ic n="check" class="ck" />{{ __('media.prio.'.$p) }}</span></label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div class="field" style="margin:0">
                                    <label for="mr-planned">{{ __('media.fm.planned') }}</label>
                                    <input id="mr-planned" class="inp" type="date" min="{{ today()->toDateString() }}" wire:model.live="plannedFor">
                                    @error('plannedFor')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </form>
                    @endcan

                    @can('claim', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.claimIntro') }}</p>
                        <button type="button" class="btn btn-p btn-w" wire:click="claim" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="user" />{{ __('media.act.claim') }}</button>
                    @endcan

                    @if ($doItMyself)
                    @can('start', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.start') }}</p>
                        <button type="button" class="btn btn-p btn-w" wire:click="start" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="play" />{{ __('media.act.startBtn') }}</button>
                    @endcan

                    @can('work', $r)
                        <form wire:submit="sendResult" class="stack" style="gap:10px">
                            <p class="act-p" style="margin:0">{{ __($r->isLinkedIn() ? 'media.act.photos' : ($r->internal_ok ? 'media.act.progress2' : 'media.act.progress')) }}</p>
                            {{-- A link, files, or both: at least one. Images and PDFs show as previews before sending. --}}
                            <div class="field" style="margin:0">
                                <label for="mr-link">{{ __('media.fm.link') }} <span class="sub">({{ __('media.fm.optional') }})</span></label>
                                <input id="mr-link" class="inp ltr" type="url" wire:model="link" placeholder="https://…" @error('link') aria-invalid="true" @enderror>
                                @error('link')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="field" style="margin:0">
                                <label class="drop resdrop" for="mr-result-files">
                                    <span class="di"><x-ic n="upload" /></span>
                                    <span><b>{{ __('media.fm.files') }}</b><small class="sub">{{ __('media.fm.filesHint') }}</small></span>
                                    <input id="mr-result-files" type="file" multiple class="vh" wire:model="resultUploads"
                                           accept="{{ collect(explode(',', \App\Services\MediaRequestWorkflow::RESULT_FILES))->map(fn ($e) => '.'.$e)->implode(',') }}">
                                    <span class="spin" wire:loading wire:target="resultUploads"></span>
                                </label>
                                @error('resultUploads.*')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                @error('files.*')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                @if ($resultUploads)
                                    <ul class="resprev">
                                        @foreach ($resultUploads as $i => $file)
                                            <li wire:key="rp-{{ $i }}">
                                                @if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->getMimeType() !== 'image/svg+xml')
                                                    <img src="{{ $file->temporaryUrl() }}" alt="">
                                                @else
                                                    <span class="rpi"><x-ic n="file" /><small class="ltr">{{ strtoupper($file->getClientOriginalExtension()) }}</small></span>
                                                @endif
                                                <span class="rpn ltr">{{ $file->getClientOriginalName() }}</span>
                                                <button type="button" class="iconbtn" wire:click="dropResultUpload({{ $i }})" aria-label="{{ __('media.proof.delete') }}"><x-ic n="x" /></button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            <div class="field" style="margin:0">
                                <label for="mr-work-note">{{ __('media.fm.note') }}</label>
                                <textarea id="mr-work-note" class="inp" rows="2" wire:model="note"></textarea>
                            </div>
                            <button type="submit" class="btn btn-p btn-w" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="send" class="flip" />{{ __($r->isLinkedIn() ? 'media.act.sendPhotos' : ($r->internal_ok ? 'media.act.sendReq' : 'media.act.sendInt')) }}</button>
                            @unless ($r->isLinkedIn())
                                <button type="button" class="btn btn-o btn-w" wire:click="shareDraft"><x-ic n="eye" />{{ __('media.act.draft') }}</button>
                            @endunless
                        </form>
                    @endcan
                    @endif

                    @can('reviewInternal', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.int') }}</p>
                        @if ($r->result_link)<a class="btn btn-g btn-w" href="{{ $r->result_link }}" target="_blank" rel="noopener noreferrer"><x-ic n="eye" />{{ __('media.link.result') }}</a>@endif
                        <x-media.results :r="$r" latest class="compact" />
                        <button type="button" class="btn btn-p btn-w" wire:click="approveInternal" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.act.intOk') }}</button>
                        <div class="act-sep" style="margin:4px 0"></div>
                        <div class="field" style="margin:0">
                            <label for="mr-int-note">{{ __('media.fm.intBack') }}</label>
                            <textarea id="mr-int-note" class="inp" rows="2" wire:model="note"></textarea>
                            @error('note')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                        <button type="button" class="btn btn-o btn-w" wire:click="returnToDesigner"><x-ic n="back" class="flip" />{{ __('media.act.intBack') }}</button>
                    @endcan

                    @can('reviewResult', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.review') }}</p>
                        @if ($r->result_link)<a class="btn btn-g btn-w" href="{{ $r->result_link }}" target="_blank" rel="noopener noreferrer"><x-ic n="eye" />{{ __('media.link.result') }}</a>@endif
                        <x-media.results :r="$r" latest class="compact" />
                        <button type="button" class="btn btn-p btn-w" wire:click="approveResult" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.act.resultOk') }}</button>
                        <div class="act-sep" style="margin:4px 0"></div>
                        <div class="field" style="margin:0">
                            <label for="mr-changes">{{ __('media.fm.changes') }}</label>
                            <textarea id="mr-changes" class="inp" rows="2" wire:model="note"></textarea>
                            @error('note')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                        <button type="button" class="btn btn-o btn-w" wire:click="requestChanges"><x-ic n="edit" />{{ __('media.act.changes') }}</button>
                    @endcan

                    {{-- LinkedIn: PR checks or translates the text, beside the photo work. --}}
                    @can('reviewText', $r)
                        <form wire:submit="approveText" class="stack" style="gap:10px" x-data x-init="$wire.postText = @js($r->answer('postText', ''))">
                            <p class="act-p" style="margin:0">{{ __('media.act.text') }}</p>
                            <div class="field" style="margin:0">
                                <label for="mr-post-text">{{ __('media.fm.postText') }}</label>
                                <textarea id="mr-post-text" class="inp" rows="8" wire:model="postText" dir="auto"></textarea>
                            </div>
                            <button type="submit" class="btn btn-p btn-w" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.act.textOk') }}</button>
                        </form>
                    @endcan

                    {{-- LinkedIn: everything the officer needs to post it, then the link to the post. --}}
                    @can('publish', $r)
                        @php($finalText = $r->answer('finalText') ?: $r->answer('postText'))
                        <p class="act-p" style="margin:0">{{ __('media.act.publish') }}</p>
                        <div class="field" style="margin:0" x-data="{ copied: false }">
                            <span class="lbl">{{ __('media.fm.postText') }}</span>
                            <p class="posttext" dir="auto">{{ $finalText }}</p>
                            <button type="button" class="btn btn-o btn-sm" x-on:click="navigator.clipboard.writeText(@js((string) $finalText)); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-ic n="file" /><span x-text="copied ? @js(__('media.proof.copied')) : @js(__('media.proof.copy'))"></span></button>
                        </div>
                        @if ($r->answer('tags'))<p class="sub" style="margin:0"><b>{{ __('media.f.tags') }}:</b> <span dir="auto">{{ $r->answer('tags') }}</span></p>@endif
                        @if ($r->result_link)<a class="btn btn-g btn-w" href="{{ $r->result_link }}" target="_blank" rel="noopener noreferrer"><x-ic n="eye" />{{ __('media.link.result') }}</a>@endif
                        <x-media.results :r="$r" latest class="compact" />
                        <form wire:submit="publish" class="stack" style="gap:10px">
                            <div class="field" style="margin:0">
                                <label for="mr-post-link">{{ __('media.fm.postLink') }}</label>
                                <input id="mr-post-link" class="inp ltr" type="url" wire:model="link" placeholder="https://www.linkedin.com/feed/update/…" required>
                                @error('link')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn btn-p btn-w" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="send" class="flip" />{{ __('media.act.published') }}</button>
                        </form>
                    @endcan

                    @if ($doItMyself)
                    @can('markReady', $r)
                        <p class="act-p" style="margin:0">{{ __('media.act.prod') }}</p>
                        <button type="button" class="btn btn-p btn-w" wire:click="markReady" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="box" />{{ __('media.act.ready') }}</button>
                    @endcan

                    @can('deliver', $r)
                        <form wire:submit="deliver" class="stack" style="gap:10px">
                            @if ($r->isPack())
                                <p class="act-p" style="margin:0">{{ __('media.act.packIntro') }}</p>
                            @else
                                <p class="act-p" style="margin:0">{{ __('media.act.deliverIntro') }}</p>
                                <div class="field" style="margin:0">
                                    <label for="mr-archive">{{ __('media.fm.archive') }}</label>
                                    <input id="mr-archive" class="inp ltr" type="url" wire:model="link" placeholder="https://…" @error('archive_link') aria-invalid="true" @enderror>
                                    @error('archive_link')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                </div>
                            @endif
                            <button type="submit" class="btn btn-p btn-w" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __($r->isPack() ? 'media.act.packBtn' : 'media.act.deliverBtn') }}</button>
                        </form>
                    @endcan
                    @endif

                    @can('reply', $r)
                        <form wire:submit="reply" class="stack" style="gap:10px">
                            <p class="act-p" style="margin:0">{{ __('media.act.info') }}</p>
                            @php($question = $history->firstWhere('description', 'info'))
                            @if ($question?->properties->get('note'))
                                <div class="banner brand"><x-ic n="msg" /><span>{{ $question->properties->get('note') }}</span></div>
                            @endif
                            <div class="field" style="margin:0">
                                <label for="mr-reply">{{ __('media.fm.reply') }}</label>
                                <textarea id="mr-reply" class="inp" rows="3" wire:model="note"></textarea>
                                @error('note')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn btn-p btn-w"><x-ic n="send" class="flip" />{{ __('media.act.reply') }}</button>
                        </form>
                    @endcan

                    @can('resume', $r)
                        <button type="button" class="btn btn-p btn-w" wire:click="resume"><x-ic n="play" />{{ __('media.act.resume') }}</button>
                    @endcan

                    @if ($r->status === S::Delivered && $r->archive_link)
                        <a class="btn btn-o btn-w" href="{{ $r->archive_link }}" target="_blank" rel="noopener noreferrer"><x-ic n="link" />{{ __('media.d.archive') }}</a>
                    @elseif (! $r->isOpen() && $r->status !== S::Delivered)
                        <p class="act-p" style="margin:0">{{ __('media.act.closed') }}</p>
                    @endif
                </div>

                @if ($sideActions)
                    <div class="act-sep"></div>
                    {{-- The other decisions, in sight: each one asks its own question when picked (why, or what is missing),
                         then is confirmed there. Only cancelling may go without a reason. --}}
                    <div x-data="{ act: null }" class="sideacts">
                        <p class="sidelab">{{ __('media.side.title') }}</p>
                        <div class="btnline" x-show="! act">
                            @foreach ($others as $ability => [$icon, $label])
                                <button type="button" @class(['btn btn-o btn-sm', 'danger' => in_array($ability, ['reject', 'cancel'], true)])
                                        x-on:click="act = '{{ $ability }}'; setTimeout(() => document.getElementById('{{ $ability === 'assign' ? 'mr-assign' : 'mr-side-'.$ability }}')?.focus(), 60)"><x-ic :n="$icon" />{{ __('media.'.$label) }}</button>
                            @endforeach
                        </div>

                        @can('assign', $r)
                            <form x-show="act === 'assign'" x-cloak wire:submit="assign" class="stack" style="gap:8px">
                                <label for="mr-assign" class="lbl">{{ __($r->assignee_id ? 'media.act.reassign' : 'media.act.assign') }}</label>
                                <select id="mr-assign" class="inp" wire:model="assigneeId">
                                    <option value="">{{ __('media.o.select') }}</option>
                                    @include('livewire.media-requests.partials.assignee-options')
                                </select>
                                @error('assigneeId')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                <div class="btnline">
                                    <button type="submit" class="btn btn-p btn-sm">{{ __('media.act.assign') }}</button>
                                    <button type="button" class="btn btn-g btn-sm" x-on:click="act = null">{{ __('media.confirm.back') }}</button>
                                </div>
                            </form>
                        @endcan

                        @foreach ($others->except('assign') as $ability => [$icon, $label, $question])
                            <form x-show="act === '{{ $ability }}'" x-cloak wire:submit="{{ $ability }}" class="stack" style="gap:8px">
                                <label for="mr-side-{{ $ability }}" class="lbl">{{ __('media.'.$question) }}@if ($ability !== 'cancel')<span class="req" aria-hidden="true">*</span>@endif</label>
                                <textarea id="mr-side-{{ $ability }}" class="inp" rows="3" wire:model="note" @if ($ability !== 'cancel') required @endif></textarea>
                                @error('note')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                <div class="btnline">
                                    <button type="submit" @class(['btn btn-sm', 'btn-p' => ! in_array($ability, ['reject', 'cancel'], true), 'btn-o danger' => in_array($ability, ['reject', 'cancel'], true)])><x-ic :n="$icon" />{{ __('media.'.$label) }}</button>
                                    <button type="button" class="btn btn-g btn-sm" x-on:click="act = null">{{ __('media.confirm.back') }}</button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </div>

</div>
