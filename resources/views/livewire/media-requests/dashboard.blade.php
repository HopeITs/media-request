@php
    $locale = app()->getLocale();
    $user = auth()->user();
    $first = $user->profile?->getAttribute('first_name_'.$locale) ?: $user->profile?->first_name_en;
    $months = $locale === 'ar'
        ? ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول']
        : ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $days = $locale === 'ar' ? ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'] : ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $bounds = collect($layout)->mapWithKeys(fn ($i) => [$i['key'] => $cards->bounds($i['key'])])->all();
    $looks = \App\Support\MediaRequests\DashboardLayout::LOOKS;
    // Server-side first paint, before the browser script takes over.
    $span = function (string $size) {
        [$c, $r] = array_map('intval', explode('x', $size));

        return 'grid-column: span '.min($c, \App\Support\MediaRequests\DashboardLayout::COLUMNS).'; grid-row: span '.$r.';';
    };
    $label = fn (string $key) => __('media.dash.card.'.$key);
    // People who only ask for work stay on this screen: a request opens in the panel here.
    $here = app(\App\Support\MediaRequests\MediaTeam::class)->isRequesterOnly($user);
    // "Nothing is waiting for you" is for the media team, who do the work; everyone else is offered help.
    $onTeam = app(\App\Support\MediaRequests\MediaTeam::class)->isMember($user->profile) || app(\App\Support\MediaRequests\MediaTeam::class)->canActAsMediaManager($user);
    $go = fn ($r) => $here
        ? new \Illuminate\Support\HtmlString('href="#" wire:click.prevent="openRequest('.(int) $r->id.')"')
        : new \Illuminate\Support\HtmlString('href="'.e(route('media-requests.show', $r)).'" wire:navigate');
@endphp

<div>
{{-- wire:key: when the server sends a different layout (reset), the home screen starts fresh. --}}
<div wire:key="home-{{ md5(json_encode($layout)) }}"
     x-data="mrHomeScreen(@js($layout), @js($bounds), @js($looks))"
     x-on:keydown.escape.window="done()">

    <section class="hello">
        <div>
            <h1><small>{{ __('media.home.hi', ['name' => $first]) }}</small>{{ $waiting->count() ? trans_choice('media.home.waiting', $waiting->count(), ['n' => $waiting->count()]) : ($onTeam ? __('media.home.clear') : __('media.home.help')) }}</h1>
            <p class="lead" x-text="editing ? @js(__('media.dash.editLead')) : @js(__('media.dash.lead'))">{{ __('media.dash.lead') }}</p>
        </div>
        <div class="btnline">
            <template x-if="! editing">
                <button type="button" class="btn btn-o" x-on:click="startEdit()"><x-ic n="layers" />{{ __('media.dash.customise') }}</button>
            </template>
            <template x-if="editing">
                <span class="btnline">
                    <button type="button" class="btn btn-g" x-on:click="mrConfirm(@js(__('media.dash.resetConfirm')), { ok: @js(__('media.dash.reset')), cancel: @js(__('media.confirm.back')) }).then((yes) => yes && $wire.resetLayout())"><x-ic n="reset" />{{ __('media.dash.reset') }}</button>
                    <button type="button" class="btn btn-o" x-on:click="gallery = true" :disabled="hiddenItems().length === 0"><x-ic n="plus" />{{ __('media.dash.add') }}</button>
                    <button type="button" class="btn btn-p" x-on:click="done()"><x-ic n="check" />{{ __('media.dash.done') }}</button>
                </span>
            </template>
        </div>
    </section>

    {{-- Past the needed-by date: a strip with a ringing clock, so it is not missed. --}}
    @if ($late)
        @php($oldest = $late['items']->first())
        <section class="lateband" role="alert" aria-live="polite">
            <span class="lb-ico"><x-ic n="clock" /></span>
            <span class="lb-t">
                <b>{{ trans_choice('media.late.'.$late['scope'], $late['items']->count(), ['n' => $late['items']->count()]) }}</b>
                <span>{{ $late['items']->take(3)->map(fn ($r) => ($r->title ?: $r->item_code).($late['scope'] === 'team' && $r->assignee ? ' ('.$r->assignee->nameForLocale($locale).')' : ''))->implode($locale === 'ar' ? '، ' : ', ') }}@if ($late['items']->count() > 3) …@endif</span>
            </span>
            <button type="button" class="btn btn-p btn-sm" wire:click="openRequest({{ $oldest->id }})"><x-ic n="arrow" class="flip" />{{ __('media.late.open', ['days' => $oldest->needed_by->diffForHumans(null, true)]) }}</button>
        </section>
    @endif
    {{--
        Decisions waiting on you, oldest first. One card at a time with arrows and dots, so the oldest
        is in front; "Show all" lays them side by side instead. The choice is kept on this device.
    --}}
    @if ($decisions->isNotEmpty())
        <section class="decide" aria-labelledby="decide-t" aria-roledescription="carousel"
                 x-data="{
                    i: 0, n: {{ $decisions->count() }}, all: false,
                    init() { try { this.all = localStorage.getItem('mr-decide') === 'all' } catch (e) {} },
                    show(k) { this.i = (k + this.n) % this.n },
                    toggle() { this.all = ! this.all; try { localStorage.setItem('mr-decide', this.all ? 'all' : 'one') } catch (e) {} },
                 }"
                 x-on:keydown.arrow-right="if (! all) show(i + (getComputedStyle($el).direction === 'rtl' ? -1 : 1))"
                 x-on:keydown.arrow-left="if (! all) show(i - (getComputedStyle($el).direction === 'rtl' ? -1 : 1))">
            <div class="decide-h">
                <h2 id="decide-t"><x-ic n="alert" />{{ trans_choice('media.decide.title', $decisions->count(), ['n' => $decisions->count()]) }}</h2>
                @if ($decisions->count() > 1)
                    <span class="btnline">
                        <template x-if="! all">
                            <span class="btnline">
                                <button type="button" class="iconbtn" x-on:click="show(i - 1)" aria-label="{{ __('media.decide.prev') }}"><x-ic n="back" class="flip" /></button>
                                <span class="num dcount" aria-live="polite"><span x-text="i + 1">1</span> / {{ $decisions->count() }}</span>
                                <button type="button" class="iconbtn" x-on:click="show(i + 1)" aria-label="{{ __('media.decide.next') }}"><x-ic n="arrow" class="flip" /></button>
                            </span>
                        </template>
                        <button type="button" class="btn-q dtoggle" x-on:click="toggle()">
                            <x-ic n="layers" /><span x-text="all ? @js(__('media.decide.one')) : @js(__('media.decide.all'))">{{ __('media.decide.all') }}</span>
                        </button>
                    </span>
                @endif
            </div>

            <div class="decide-track" :class="{ all }">
                @foreach ($decisions as $r)
                    @php($what = match ($r->status) { 'pm_approval' => 'pm', 'mm_approval' => 'mm', 'int_review' => 'check', 'req_review' => 'review', default => 'info' })
                    @php($since = $r->status_changed_at ?? $r->created_at)
                    <article @class(['dcard', 'old' => $since->lt(now()->subDay())]) wire:key="dc-{{ $r->id }}"
                             :class="{ off: ! (all || i === {{ $loop->index }}) }" @unless ($loop->first) style="display:none" x-init="$el.style.removeProperty('display')" @endunless
                             aria-roledescription="slide" aria-label="{{ $loop->iteration }} / {{ $decisions->count() }}">
                        <span class="dwhat"><x-ic :n="['pm' => 'shield', 'mm' => 'shield', 'check' => 'eye', 'review' => 'eye', 'info' => 'msg'][$what]" />{{ __('media.decide.'.$what) }}</span>
                        <x-media.name :r="$r" class="nm" />
                        <span class="dmeta">
                            <span><x-ic n="user" />{{ $r->requester?->nameForLocale($locale) }}</span>
                            @if ($r->needed_by)<span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>@endif
                            <span class="dsince">{{ __('media.decide.since', ['t' => $since->diffForHumans()]) }}</span>
                        </span>
                        <button type="button" class="btn btn-p btn-sm" wire:click="openRequest({{ $r->id }})"><x-ic n="arrow" class="flip" />{{ __('media.decide.open.'.$what) }}</button>
                    </article>
                @endforeach
            </div>

            @if ($decisions->count() > 1)
                <div class="ddots" x-show="! all" role="tablist" aria-label="{{ __('media.decide.pick') }}">
                    @foreach ($decisions as $r)
                        <button type="button" role="tab" x-on:click="show({{ $loop->index }})" :aria-selected="i === {{ $loop->index }}" :class="{ on: i === {{ $loop->index }} }" aria-label="{{ $loop->iteration }}"></button>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
    {{-- The grid. Tiles are drawn once by the server; moving, resizing and hiding only change them in place. --}}
    <div class="hs-wrap">
    {{-- Virtual grid: faint cells behind the tiles while editing, so you can see where a tile will sit. --}}
    <div class="hs hs-guides" x-ref="guides" x-show="editing" x-cloak aria-hidden="true"></div>
    <div class="hs" :class="{ editing, wiggle: wiggling }" x-ref="grid"
         x-on:pointerdown="press($event)" x-on:pointerup="release()" x-on:pointerleave="release()" x-on:pointercancel="release()"
         x-on:click.capture="swallowClick($event)" x-on:contextmenu="if (editing || pressed) $event.preventDefault()">
        @foreach ($layout as $item)
            @php($key = $item['key'])
            <div class="tile {{ $cards->isApp($key) ? 'is-app' : 'is-widget' }}" data-key="{{ $key }}"
                 style="{{ $span($item['size']) }}{{ $item['hidden'] ? 'display:none;' : '' }}"
                 :style="span('{{ $key }}')"
                 :class="{ pinned: pinned('{{ $key }}'), resizing: resizing === '{{ $key }}' }"
                 x-show="! hidden('{{ $key }}')"
                 wire:key="tile-{{ $key }}">

                {{-- Edit controls: remove outside the top corner (like a phone), pin and resize inside the tile so they never overlap a neighbour. --}}
                <button type="button" class="tbtn t-remove" x-show="editing" x-cloak x-on:click="remove('{{ $key }}')" aria-label="{{ __('media.dash.hide') }}: {{ $label($key) }}"><x-ic n="x" /></button>
                @unless ($cards->isApp($key))
                    <button type="button" class="tbtn t-pin" x-show="editing" x-cloak x-on:click="togglePin('{{ $key }}')" :aria-pressed="pinned('{{ $key }}')" aria-label="{{ __('media.dash.pin') }}: {{ $label($key) }}"><x-ic n="pin" /></button>
                    @if (isset($looks[$key]))
                        <button type="button" class="tbtn t-style" x-show="editing" x-cloak x-on:click="cycleStyle('{{ $key }}')" aria-label="{{ __('media.dash.style') }}: {{ $label($key) }}" title="{{ __('media.dash.style') }}"><x-ic n="clock" /><span x-text="styleOf('{{ $key }}') === 'analog' ? @js(__('media.dash.look.digital')) : @js(__('media.dash.look.analog'))"></span></button>
                    @endif
                @endunless
                @if ($cards->canResize($key))
                    <button type="button" class="grip" x-show="editing" x-cloak
                            x-on:pointerdown="resizeStart($event, '{{ $key }}')"
                            x-on:keydown="resizeKey($event, '{{ $key }}')"
                            aria-label="{{ __('media.dash.resize') }}: {{ $label($key) }}"
                            :aria-valuetext="sizeText('{{ $key }}')"
                            title="{{ __('media.dash.resizeHint') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.5 8.5v9h-9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <span class="sizebadge num" x-show="resizing === '{{ $key }}'" x-cloak x-text="resizeLabel"></span>
                @endif

                @if ($cards->isApp($key))
                    {{-- App shortcut --}}
                    @if ($key === 'app.new')
                        <button type="button" class="app" x-on:click="if (! editing) $dispatch('media-wizard-open')">
                            <span class="appicon brand"><x-ic n="plus" /></span><span class="appname">{{ $label($key) }}</span>
                        </button>
                    @elseif ($key === 'app.generator')
                        {{-- The centre's do-it-yourself site, in a new tab. --}}
                        <a class="app" href="{{ app(\App\Support\MediaRequests\HcGenerator::class)->url() }}" target="_blank" rel="noopener">
                            <span class="appicon"><x-ic n="wand" /></span><span class="appname" lang="en">{{ $label($key) }}</span>
                        </a>
                    @else
                        <a class="app" href="{{ route($cards->route($key)) }}" wire:navigate>
                            <span class="appicon"><x-ic :n="$cards->icon($key)" />
                                @if ($key === 'app.my' && $waiting->count())<span class="cnt num">{{ $waiting->count() }}</span>@endif
                                @if ($key === 'app.bell' && $user->unreadNotifications()->count())<span class="cnt num">{{ $user->unreadNotifications()->count() }}</span>@endif
                            </span>
                            <span class="appname">{{ $label($key) }}</span>
                        </a>
                    @endif
                @else
                    {{-- Widget --}}
                    <section class="w" aria-labelledby="wt-{{ $key }}">
                        @unless ($key === 'clock' || $key === 'ask')
                            <header class="wh">
                                <h2 id="wt-{{ $key }}"><x-ic :n="$cards->icon($key)" />{{ $label($key) }}</h2>
                                <x-ic n="pin" class="pinmark" x-show="pinned('{{ $key }}')" x-cloak />
                                @if ($key === 'waiting' && ! $here)<a href="{{ route('media-requests.index') }}" wire:navigate class="btn-q" style="min-height:30px;padding:0;font-size:14px">{{ __('media.dash.all') }}</a>@endif
                            </header>
                        @else
                            <h2 id="wt-{{ $key }}" class="vh">{{ $label($key) }}</h2>
                        @endunless

                        <div class="wb">
                            @switch($key)
                                @case('ask')
                                    <form class="askbar" x-data="{ text: '' }" x-on:submit.prevent="if (text.trim()) { $dispatch('media-wizard-open', { prompt: text }); text = '' }">
                                        <x-ic n="spark" class="spark" />
                                        <label for="ask" class="vh">{{ __('media.ai.title') }}</label>
                                        <input id="ask" x-model="text" type="text" autocomplete="off" placeholder="{{ __('media.ai.ph') }}" x-data="mrTypewriter(@js(__('media.ai.examples')))">
                                        <button class="btn btn-o" type="submit"><x-ic n="spark" /><span class="ask-go">{{ __('media.ai.go') }}</span></button>
                                    </form>
                                    @break

                                @case('flow')
                                    {{-- Where every request is: the total, then the stages in order; a stage opens its requests in a window. --}}
                                    <div class="flow" x-data="{ stage: null }" x-on:keydown.escape.window="stage = null">
                                        <p class="flowsum">
                                            @if ($flow['total'] === 0){{ __('media.flow.empty.'.$flow['scope']) }}
                                            @else<b>{{ rtrim(trans_choice('media.flow.total.'.$flow['scope'], $flow['total'], ['n' => $flow['total']]), ':') }}</b>@endif
                                            @if ($flow['waitingYou'])<span class="flowyou"><x-ic n="arrow" class="flip" />{{ trans_choice('media.flow.you', $flow['waitingYou'], ['n' => $flow['waitingYou']]) }}</span>@endif
                                        </p>

                                        <ol class="flowline" role="tablist">
                                            @foreach ($flow['stages'] as $stage => $items)
                                                @continue($stage === 'stopped' && $items->isEmpty())
                                                <li @class(['empty' => $items->isEmpty(), 'end' => in_array($stage, ['done', 'stopped'], true)])>
                                                    <button type="button" role="tab" class="fnode" @disabled($items->isEmpty())
                                                            x-on:click="stage = @js($stage)" :aria-selected="stage === @js($stage)" :class="{ on: stage === @js($stage) }" aria-haspopup="dialog">
                                                        <span class="fi"><x-ic :n="\App\Support\MediaRequests\RequestFlow::ICONS[$stage]" /></span>
                                                        <b class="num">{{ $items->count() }}</b>
                                                        <span class="fl">{{ __('media.flow.stage.'.$stage) }}</span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ol>

                                        {{-- The stage's requests: a window over the page (moved to <body>, out of the card). --}}
                                        <template x-teleport="body">
                                        <div class="daypop-back" x-show="stage" x-cloak x-on:click.self="stage = null">
                                        @foreach ($flow['stages'] as $stage => $items)
                                            @continue($items->isEmpty())
                                            <div class="daypop flowpop" role="dialog" aria-modal="true" aria-labelledby="flow-t-{{ $stage }}" x-show="stage === @js($stage)" x-cloak>
                                                <div class="bellhead">
                                                    <h2 id="flow-t-{{ $stage }}"><x-ic :n="\App\Support\MediaRequests\RequestFlow::ICONS[$stage]" />{{ __('media.flow.stage.'.$stage) }} <span class="num sub">{{ $items->count() }}</span></h2>
                                                    <button type="button" class="iconbtn" x-on:click="stage = null" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
                                                </div>
                                            <ul class="wlist flowlist">
                                                @foreach ($items->take(30) as $r)
                                                    <li><a {{ $go($r) }}>
                                                        <x-media.name :r="$r" class="nm" />
                                                        <span class="meta" style="font-size:13px">
                                                            <x-media.status :r="$r" />
                                                            @if ($r->assignee)<span><x-ic n="user" />{{ $r->assignee->nameForLocale($locale) }}</span>@endif
                                                            @if ($r->status_changed_at && $r->isOpen())<span class="sub">{{ __('media.flow.since', ['t' => $r->status_changed_at->diffForHumans(null, true)]) }}</span>@endif
                                                            @if ($r->needed_by)<span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>@endif
                                                        </span>
                                                    </a></li>
                                                @endforeach
                                                @if ($items->count() > 30)<li class="sub" style="padding:6px 8px">{{ trans_choice('media.crew.more', $items->count() - 30, ['n' => $items->count() - 30]) }}</li>@endif
                                            </ul>
                                            </div>
                                        @endforeach
                                        </div>
                                        </template>
                                    </div>
                                    @break
                                @case('clock')
                                    {{-- Damascus time. Digital, or an analogue face; the look is chosen in Customise. --}}
                                    <div class="clock" :class="{ analog: styleOf('clock') === 'analog' }" x-data="{
                                            now: new Date(), loc: @js($locale === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB'),
                                            t() { return this.now.toLocaleTimeString(this.loc, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Damascus' }) },
                                            wd() { return this.now.toLocaleDateString(this.loc, { weekday: 'long', timeZone: 'Asia/Damascus' }) },
                                            d() { return this.now.toLocaleDateString(this.loc, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Damascus' }) },
                                            part(p) { return +new Intl.DateTimeFormat('en-GB', { hour: 'numeric', minute: 'numeric', second: 'numeric', hourCycle: 'h23', timeZone: 'Asia/Damascus' }).formatToParts(this.now).find((x) => x.type === p).value },
                                            angle(hand) { const h = this.part('hour'), m = this.part('minute'), s = this.part('second'); return { h: (h % 12 + m / 60) * 30, m: (m + s / 60) * 6, s: s * 6 }[hand]; },
                                         }" x-init="setInterval(() => now = new Date(), 1000)">
                                        <template x-if="styleOf('clock') !== 'analog'">
                                            <div style="display:contents">
                                                <span class="cwd2" x-text="wd()"></span>
                                                <span class="ct num" x-text="t()">{{ now()->format('H:i') }}</span>
                                                <span class="cdate" x-text="d()"></span>
                                            </div>
                                        </template>
                                        <template x-if="styleOf('clock') === 'analog'">
                                            <div style="display:contents">
                                                <svg class="face" viewBox="0 0 100 100" role="img" :aria-label="t()">
                                                    <circle class="rim" cx="50" cy="50" r="47" />
                                                    @for ($i = 0; $i < 60; $i++)
                                                        @if ($i % 5 === 0)
                                                            <line class="tick major" x1="50" y1="7" x2="50" y2="14" transform="rotate({{ $i * 6 }} 50 50)" />
                                                        @else
                                                            <line class="tick" x1="50" y1="7" x2="50" y2="10" transform="rotate({{ $i * 6 }} 50 50)" />
                                                        @endif
                                                    @endfor
                                                    <line class="hand h" x1="50" y1="54" x2="50" y2="28" :transform="'rotate(' + angle('h') + ' 50 50)'" />
                                                    <line class="hand m" x1="50" y1="56" x2="50" y2="15" :transform="'rotate(' + angle('m') + ' 50 50)'" />
                                                    <line class="hand s" x1="50" y1="60" x2="50" y2="12" :transform="'rotate(' + angle('s') + ' 50 50)'" />
                                                    <circle class="hub" cx="50" cy="50" r="2.6" />
                                                </svg>
                                                <span class="aside">
                                                    <span class="cwd2" x-text="wd()"></span>
                                                    <b class="num" style="font-size:clamp(18px,14cqh,34px)" x-text="t()"></b>
                                                    <span class="cdate" x-text="d()"></span>
                                                </span>
                                            </div>
                                        </template>
                                    </div>
                                    @break

                                @case('waiting')
                                    @if ($waiting->isEmpty())
                                        <div class="wempty"><span class="ei"><x-ic n="check" /></span><span>{{ $onTeam ? __('media.home.clear') : __('media.home.nothingForYou') }}</span></div>
                                    @else
                                        <ul class="wlist">
                                            @foreach ($waiting->take(8) as $r)
                                                <li>
                                                    <a {{ $go($r) }}>
                                                        <x-media.name :r="$r" class="nm" />
                                                        <span class="meta" style="font-size:13px"><x-media.status :r="$r" />@if ($r->needed_by)<span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>@endif</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @break

                                @case('mine')
                                    @if ($mine->isEmpty())
                                        <div class="wempty"><span class="ei"><x-ic n="inbox" /></span><span>{{ __('media.dash.mineNone') }}</span></div>
                                    @else
                                        <ul class="wlist">
                                            @foreach ($mine as $r)
                                                <li @class(['done' => ! $r->isOpen()])>
                                                    <a {{ $go($r) }}>
                                                        <x-media.name :r="$r" class="nm" />
                                                        <span class="meta" style="font-size:13px"><x-media.status :r="$r" />@if ($r->needed_by)<span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>@endif</span>
                                                        @if ($r->isOpen())<x-media.progress :r="$r" />@endif
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @break

                                @case('bell')
                                    @include('livewire.media-requests.partials.notifications')
                                    @break

                                @case('calendar')
                                    <div class="cal">
                                        <div class="calnav">
                                            <button type="button" class="iconbtn" wire:click="shiftMonth(-1)" aria-label="{{ __('media.cal.prev') }}"><x-ic n="back" class="flip" /></button>
                                            <b class="calttl">{{ $months[$calendar['first']->month - 1] }} <span class="num">{{ $calendar['first']->year }}</span></b>
                                            <button type="button" class="iconbtn" wire:click="shiftMonth(1)" aria-label="{{ __('media.cal.next') }}"><x-ic n="arrow" class="flip" /></button>
                                            @unless ($calendar['first']->isSameMonth(now()))
                                                <button type="button" class="btn-q" style="min-height:32px" wire:click="thisMonth">{{ __('media.cal.today') }}</button>
                                            @endunless
                                        </div>
                                        <div class="calgrid" role="grid">
                                            @foreach ($days as $d)<div class="cwd" role="columnheader">{{ $d }}</div>@endforeach
                                            @foreach ($calendar['weeks'] as $week)
                                                @foreach ($week as $day)
                                                    <div @class(['cd', 'out' => ! $day['in'], 'today' => $day['today'], 'has' => $day['items']->isNotEmpty()]) role="gridcell"
                                                         x-on:dblclick="if (! editing) $wire.pickDay('{{ $day['date']->toDateString() }}')" title="{{ __('media.cal.dblclick') }}">
                                                        <span class="cn num">{{ $day['date']->day }}</span>
                                                        @foreach ($day['items']->take(2) as $r)
                                                            <a {{ $go($r) }} @class(['cc', 'mine' => Gate::allows('isOwner', $r), 'done' => ! $r->isOpen()]) title="{{ $r->displayName() }}"><span class="ltr">{{ $r->title ?: $r->item_code }}</span></a>
                                                        @endforeach
                                                        @if ($day['items']->count() > 2)<span class="more-n">+{{ $day['items']->count() - 2 }}</span>@endif
                                                    </div>
                                                @endforeach
                                            @endforeach
                                        </div>
                                    </div>
                                    @break

                                @case('notes')
                                    <div class="notesbox" x-data="{ saved: true }">
                                        <label for="dash-notes" class="vh">{{ $label('notes') }}</label>
                                        <textarea id="dash-notes" class="inp notes" wire:model="notes" placeholder="{{ __('media.dash.notesPh') }}"
                                                  x-on:input="saved = false"
                                                  x-on:input.debounce.800ms="$wire.saveNotes().then(() => saved = true)"></textarea>
                                        <p class="sub notesstate" aria-live="polite"><x-ic n="shield" /><span x-text="saved ? @js(__('media.dash.notesSaved')) : @js(__('media.dash.notesSaving'))"></span></p>
                                    </div>
                                    @break

                                @case('team')
                                    {{-- Who is working on what right now; free people last. --}}
                                    <ul class="crew">
                                        @foreach ($team as $row)
                                            @if ($row['employee'] === null)
                                                <li class="crew-claim">
                                                    <span class="who"><x-ic n="inbox" />{{ __('media.dash.toClaim') }} <b class="num">{{ $row['n'] }}</b></span>
                                                    @foreach ($row['taken']->take(2) as $r)<a {{ $go($r) }} class="job"><span class="ltr">{{ $r->title ?: $r->item_code }}</span></a>@endforeach
                                                </li>
                                                @continue
                                            @endif
                                            <li>
                                                <span class="who">
                                                    <span @class(['dot', 'busy' => $row['now']->isNotEmpty(), 'idle' => $row['n'] === 0])></span>
                                                    <b>{{ $row['employee']->nameForLocale($locale) }}</b>
                                                    @if ($row['boost'])<span class="earlyme boostme" title="{{ trans_choice('media.boost.person', $row['boost'], ['n' => $row['boost']]) }}"><x-ic n="rocket" /><span class="num">{{ $row['boost'] }}</span></span>@endif
                                                    @if ($row['early'])<span class="earlyme" title="{{ trans_choice('media.early.person', $row['early'], ['n' => $row['early']]) }}"><x-ic n="bolt" /><span class="num">{{ $row['early'] }}</span></span>@endif
                                                    @foreach (app(\App\Support\MediaRequests\MediaTeam::class)->skillsOf($row['employee']) as $s)<x-ic :n="config('media-requests.specialties.'.$s.'.icon')" class="skill" title="{{ __('media.spec.'.$s) }}" />@endforeach
                                                    <small class="sub">@if ($row['employee']->city)· {{ $locale === 'ar' ? $row['employee']->city->name_ar : $row['employee']->city->name_en }}@endif @if ($row['employee']->work_hours)· <span class="num ltr" title="{{ __('media.people.partTime') }}">{{ $row['employee']->work_hours }}</span>@endif</small>
                                                </span>
                                                @if ($now = $row['now']->first())
                                                    <span class="what">{{ __('media.crew.now') }}
                                                        <a {{ $go($now) }} class="job"><span class="ltr">{{ $now->title ?: $now->item_code }}</span></a>
                                                        @if ($now->status_changed_at)<small class="sub">· {{ $now->status_changed_at->diffForHumans(null, true) }}</small>@endif
                                                    </span>
                                                @elseif ($taken = $row['taken']->first())
                                                    <span class="what">{{ __('media.crew.taken') }} <a {{ $go($taken) }} class="job"><span class="ltr">{{ $taken->title ?: $taken->item_code }}</span></a></span>
                                                @elseif ($row['n'] === 0)
                                                    <span class="what sub">{{ __('media.crew.free') }}</span>
                                                @endif
                                                @php($more = $row['n'] - ($row['now']->isNotEmpty() || $row['taken']->isNotEmpty() ? 1 : 0))
                                                @if ($more > 0)
                                                    <small class="sub more">{{ trans_choice('media.crew.more', $more, ['n' => $more]) }}@if ($row['other']->isNotEmpty()) ({{ $row['other']->map(fn ($r) => \App\Enums\MediaRequestStatus::label($r->status, $r->type))->unique()->implode($locale === 'ar' ? '، ' : ', ') }})@endif</small>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @break
                                @case('proof')
                                    {{-- The whole proofreader in the card: type or upload a file, check, read the result here. --}}
                                    <livewire:media-requests.proofreader-panel :inline="true" wire:key="proof-card" />
                                    @break
                                @case('pack')
                                    <form method="GET" action="{{ route('media-requests.pack') }}" class="packmini">
                                        <label for="dash-pack" class="vh">{{ __('media.hp.ph') }}</label>
                                        <div class="search" style="max-width:none">
                                            <x-ic n="search" />
                                            <input id="dash-pack" name="q" class="inp" type="search" placeholder="{{ __('media.dash.packPh') }}">
                                        </div>
                                        <button type="submit" class="btn btn-o btn-sm"><x-ic n="box" />HC_PACK</button>
                                    </form>
                                    @break
                            @endswitch
                        </div>
                    </section>
                @endif
            </div>
        @endforeach
    </div>
    </div>

    {{-- Gallery: what can be added back. A bottom sheet on phones, a panel on larger screens. --}}
    <div class="sheet-back" x-show="gallery" x-cloak x-transition.opacity x-on:click.self="gallery = false">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="gal-t" x-show="gallery" x-transition:enter="sheet-in" x-transition:leave="sheet-out">
            <div class="sheet-h">
                <h2 id="gal-t">{{ __('media.dash.add') }}</h2>
                <button type="button" class="iconbtn" x-on:click="gallery = false" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
            </div>
            <div class="gal">
                @foreach ($layout as $item)
                    @php($key = $item['key'])
                    <button type="button" class="galitem" x-show="hidden('{{ $key }}')" x-on:click="add('{{ $key }}')">
                        <span @class(['appicon', 'brand' => $key === 'app.new'])><x-ic :n="$cards->icon($key)" /></span>
                        <span><b>{{ $label($key) }}</b><small>{{ __($cards->isApp($key) ? 'media.dash.kindApp' : 'media.dash.kindWidget') }}</small></span>
                        <x-ic n="plus" />
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    </div>

    {{-- A calendar day, from a double click: a new request for it, and the requests of that day the person may see. --}}
    @if ($pickedDay)
        @php($picked = \Illuminate\Support\Carbon::parse($pickedDay))
        @php($canAsk = $user->hasActivePermission('media-requests.create') && $picked->gte(today()) && app(\App\Support\MediaRequests\WorkCalendar::class)->isWorkDay($picked))
        {{-- A small window, only as tall as what it holds. --}}
        <div class="daypop-back" wire:click.self="closeDay" x-data x-on:keydown.escape.window="$wire.closeDay()">
            <div class="daypop" role="dialog" aria-modal="true" aria-labelledby="day-t">
                <div class="bellhead">
                    <h2 id="day-t"><x-ic n="cal" />{{ $picked->translatedFormat('l') }} <span class="num">{{ $picked->format('d-m-Y') }}</span></h2>
                    <button type="button" class="iconbtn" wire:click="closeDay" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
                </div>

                @if ($canAsk)
                    <button type="button" class="btn btn-p" style="width:100%" x-on:click="$wire.closeDay(); $dispatch('media-wizard-open', { date: @js($pickedDay) })"><x-ic n="plus" />{{ __('media.cal.newOnDay') }}</button>
                @elseif (! app(\App\Support\MediaRequests\WorkCalendar::class)->isWorkDay($picked))
                    <p class="hint workhint warn"><x-ic n="alert" />{{ __('media.err.weekend') }}</p>
                @elseif ($picked->lt(today()))
                    <p class="hint">{{ __('media.cal.past') }}</p>
                @endif

                <h3 class="bellsec">{{ trans_choice('media.cal.onDay', $dayRequests->count(), ['n' => $dayRequests->count()]) }}</h3>
                @if ($dayRequests->isEmpty())
                    <p class="sub">{{ __('media.cal.noneOnDay') }}</p>
                @else
                    <ul class="wlist">
                        @foreach ($dayRequests as $r)
                            <li><a href="#" wire:click.prevent="openRequest({{ $r->id }}); closeDay()">
                                <x-media.name :r="$r" class="nm" />
                                <span class="meta" style="font-size:13px"><x-media.status :r="$r" />@if ($r->assignee)<span><x-ic n="user" />{{ $r->assignee->nameForLocale($locale) }}</span>@endif</span>
                            </a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
    {{-- Notifications, from the bell in the top bar: read and opened here, without leaving the home screen. --}}
    @if ($bellOpen)
        {{-- A drop-down under the bell, never taller than the screen; the list scrolls inside it. --}}
        <div class="belldrop-back" wire:click.self="toggleBell" x-data x-on:keydown.escape.window="$wire.toggleBell()">
            <div class="belldrop" role="dialog" aria-modal="true" aria-labelledby="bell-t">
                <div class="bellhead">
                    <h2 id="bell-t"><x-ic n="bell" />{{ __('media.bell.title') }}</h2>
                    <button type="button" class="iconbtn" wire:click="toggleBell" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
                </div>
                @include('livewire.media-requests.partials.notifications')
            </div>
        </div>
    @endif
    {{-- A request opened from a card: shown here, over the home screen, instead of on another page. --}}
    @if ($openRequest)
        <div class="sheet-back reqpanel-back" wire:click.self="closeRequest" x-data x-on:keydown.escape.window="$wire.closeRequest()">
            <div class="reqpanel" role="dialog" aria-modal="true" aria-label="{{ $openRequest->displayName() }}">
                <button type="button" class="iconbtn reqpanel-x" wire:click="closeRequest" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
                <livewire:media-requests.show :media-request="$openRequest" :embedded="true" :key="'open-'.$openRequest->id" />
            </div>
        </div>
    @endif

    <livewire:media-requests.request-wizard />
</div>
