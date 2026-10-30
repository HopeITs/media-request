@php
    $user = auth()->user();
    $team = app(\App\Support\MediaRequests\MediaTeam::class);
    $isMediaTeam = $team->isMember($user->profile) || $team->canActAsMediaManager($user);
    $managesPrograms = $team->managedProgramIds($user->profile) !== [] || $user->myRole() === 'super-admin';
    $unread = $user->unreadNotifications()->count();
    $locale = app()->getLocale();
    $name = $user->profile?->nameForLocale($locale) ?? $user->email;
    $initials = mb_strtoupper(mb_substr($user->profile?->first_name_en ?? $user->email, 0, 1).mb_substr($user->profile?->last_name_en ?? '', 0, 1));
    $nav = array_filter([
        ['media-requests.home', 'nav.home', 'home', $user->hasActivePermission('media-requests.view-own'), ['media-requests.home']],
        ['media-requests.index', 'nav.my', 'inbox', $user->hasActivePermission('media-requests.view-own'), ['media-requests.index', 'media-requests.show']],
        ['media-requests.approvals', 'nav.approvals', 'shield', $managesPrograms && $user->hasActivePermission('media-requests.approve'), ['media-requests.approvals']],
        ['media-requests.board', 'nav.board', 'board', $isMediaTeam && $user->hasActivePermission('media-requests.work'), ['media-requests.board']],
        ['media-requests.drive', 'nav.drive', 'layers', $user->hasActivePermission('media-requests.view-own'), ['media-requests.drive']],
        ['media-requests.stats', 'nav.stats', 'chart', $isMediaTeam && $user->hasActivePermission('media-requests.work'), ['media-requests.stats']],
        ['media-requests.pack', 'nav.pack', 'box', $user->hasActivePermission('media-requests.create'), ['media-requests.pack']],
        ['media-requests.log', 'nav.log', 'list', $isMediaTeam && $user->hasActivePermission('media-requests.view-log'), ['media-requests.log']],
        ['media-requests.assistant', 'nav.assistant', 'spark', $isMediaTeam && $user->hasActivePermission('media-requests.view-log'), ['media-requests.assistant']],
        ['people.index', 'nav.people', 'users', $user->hasActivePermission('people.manage'), ['people.index']],
    ], fn ($item) => $item[3]);
    // People who only ask for work have the home screen only: no page links, and the bell is a card there.
    $requesterOnly = $team->isRequesterOnly($user);
    // Every page uses the whole width of a big screen, like the home screen.
    $wide = true;
    if ($requesterOnly) {
        $nav = [];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" data-user="{{ $user->id }}" data-got-it="{{ __('media.hint.gotIt') }}">
<head>
    <x-layouts.head :title="$title ?? null" />
</head>
<body>
    <a href="#main" class="skip">{{ __('media.skipTo') }}</a>

    <header class="top">
        <div @class(['in', 'wide' => $wide])>
            <a href="{{ route('media-requests.home') }}" class="brand" wire:navigate>
                <img src="{{ asset('img/hope-center.svg') }}" alt="{{ $locale === 'ar' ? 'مركز الرجاء المسيحي' : 'Christian Hope Center' }}" width="100" height="34">
                <span class="app">{{ __('media.app') }}</span>
            </a>

            {{-- The everyday pages in the bar; the occasional ones (log, teaching, people) under "More". --}}
            @php
                $occasional = ['media-requests.log', 'media-requests.assistant', 'people.index'];
                $navMore = collect($nav)->filter(fn ($n) => in_array($n[0], $occasional, true));
            @endphp
            <nav class="nav" aria-label="{{ __('media.app') }}">
                @foreach ($nav as [$route, $label, $icon, , $active])
                    @continue(in_array($route, $occasional, true))
                    <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs(...$active)) aria-current="page" @endif>
                        <x-ic :n="$icon" />{{ __('media.'.$label) }}
                    </a>
                @endforeach
                @if ($navMore->isNotEmpty())
                    <button type="button" class="navmore" popovertarget="nav-more" @if ($navMore->contains(fn ($n) => request()->routeIs(...$n[4]))) aria-current="page" @endif
                            x-data x-on:click="const r = $el.getBoundingClientRect(), m = document.getElementById('nav-more'); m.style.top = (r.bottom + 4) + 'px';
                                requestAnimationFrame(() => { const w = m.offsetWidth, rtl = document.dir === 'rtl'; m.style.left = Math.max(8, Math.min(innerWidth - w - 8, rtl ? r.right - w : r.left)) + 'px'; })">
                        <x-ic n="more" />{{ __('media.nav.more') }}
                    </button>
                @endif
            </nav>
            @if ($navMore->isNotEmpty())
                {{-- A popover (the top layer), so the bar's scrolling never cuts it off. --}}
                <div id="nav-more" class="navmenu" popover>
                    @foreach ($navMore as [$route, $label, $icon, , $active])
                        <a href="{{ route($route) }}" wire:navigate x-data x-on:click="document.getElementById('nav-more').hidePopover()" @if (request()->routeIs(...$active)) aria-current="page" @endif>
                            <x-ic :n="$icon" />{{ __('media.'.$label) }}
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="tools">
                @if ($user->hasActivePermission('media-requests.create'))
                    <a href="{{ app(\App\Support\MediaRequests\HcGenerator::class)->url() }}" target="_blank" rel="noopener" class="iconbtn" title="HC Generator" aria-label="HC Generator">
                        <x-ic n="wand" />
                    </a>
                @endif
                {{-- The bell opens the notifications on the home screen. --}}
                <a href="{{ route('media-requests.home', ['bell' => 1]) }}" wire:navigate class="iconbtn" aria-label="{{ __('media.bell.title') }}{{ $unread ? ' ('.$unread.')' : '' }}">
                    <x-ic n="bell" />
                    @if ($unread)<span class="cnt num" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
                </a>
                {{-- Light / dark: remembered on this device; until chosen, follows the system. --}}
                <button type="button" class="iconbtn" x-data="{ dark: false }"
                        x-init="dark = document.documentElement.dataset.theme ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches"
                        x-on:click="dark = ! dark; document.documentElement.dataset.theme = dark ? 'dark' : 'light'; try { localStorage.setItem('mr-theme', dark ? 'dark' : 'light') } catch (e) {}"
                        :aria-pressed="dark" aria-label="{{ __('media.theme.dark') }}" title="{{ __('media.theme.dark') }}">
                    <x-ic n="moon" x-show="! dark" /><x-ic n="sun" x-show="dark" x-cloak />
                </button>
                <a href="{{ route('lang', $locale === 'ar' ? 'en' : 'ar') }}" class="langbtn" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}">
                    <x-ic n="globe" />{{ $locale === 'ar' ? 'EN' : 'ع' }}
                </a>
                <div class="me" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" x-on:click="open = !open" :aria-expanded="open" aria-haspopup="true">
                        <span class="av" aria-hidden="true">{{ $initials }}</span><span class="wn">{{ $name }}</span><x-ic n="chev" />
                    </button>
                    <div class="menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                        <div class="who"><b>{{ $name }}</b><small class="ltr">{{ $user->email }}</small></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"><x-ic n="logout" class="flip" />{{ __('media.logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main id="main" @class(['in', 'wide' => $wide]) tabindex="-1">
        {{ $slot }}
    </main>

    {{--
        On a phone the app looks like an app: a bar at the bottom with Home, the main page for the
        person, a raised + in the middle (new request, from any page), the notifications, and More
        (every other page and the settings, in a sheet). The top bar keeps the logo only.
    --}}
    @php
        $canCreate = $user->hasActivePermission('media-requests.create');
        // The second tab: the board for the media team, their requests for everyone else; none for people with the home screen only.
        $find = fn (string $route) => collect($nav)->firstWhere(0, $route);
        $second = $requesterOnly ? null : ($find('media-requests.board') ?? $find('media-requests.index'));
        $more = collect($nav)->reject(fn ($n) => in_array($n[0], ['media-requests.home', $second[0] ?? ''], true));
    @endphp
    <nav class="tabbar" aria-label="{{ __('media.app') }}" x-data="{ more: false }" x-on:keydown.escape.window="more = false">
        <a href="{{ route('media-requests.home') }}" wire:navigate @if (request()->routeIs('media-requests.home') && ! request('bell')) aria-current="page" @endif>
            <x-ic n="home" /><span>{{ __('media.nav.home') }}</span>
        </a>
        @if ($second)
            <a href="{{ route($second[0]) }}" wire:navigate @if (request()->routeIs(...$second[4])) aria-current="page" @endif>
                <x-ic :n="$second[2]" /><span>{{ __('media.'.$second[1]) }}</span>
            </a>
        @endif
        @if ($canCreate)
            <button type="button" class="tb-new" x-on:click="$dispatch('media-wizard-open')" aria-label="{{ __('media.dash.card.app.new') }}"><x-ic n="plus" /></button>
        @endif
        <a href="{{ route('media-requests.home', ['bell' => 1]) }}" wire:navigate @if (request('bell')) aria-current="page" @endif>
            <span class="tb-ic"><x-ic n="bell" />@if ($unread)<span class="cnt num" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif</span>
            <span>{{ __('media.bell.title') }}</span>
        </a>
        <button type="button" x-on:click="more = true" :aria-expanded="more" aria-haspopup="dialog">
            <x-ic n="more" /><span>{{ __('media.nav.more') }}</span>
        </button>

        <template x-teleport="body">
            <div class="sheet-back tb-sheet-back" x-show="more" x-cloak x-transition.opacity x-on:click.self="more = false">
                <div class="tb-sheet" role="dialog" aria-modal="true" aria-label="{{ __('media.nav.more') }}" x-show="more" x-transition:enter.duration.200ms>
                    <span class="tb-grab" aria-hidden="true"></span>
                    <div class="tb-who"><span class="av" aria-hidden="true">{{ $initials }}</span><span><b>{{ $name }}</b><small class="ltr">{{ $user->email }}</small></span></div>
                    @if ($more->isNotEmpty())
                        <div class="tb-grid">
                            @foreach ($more as [$route, $label, $icon, , $active])
                                <a href="{{ route($route) }}" wire:navigate x-on:click="more = false" @if (request()->routeIs(...$active)) aria-current="page" @endif>
                                    <span class="appicon"><x-ic :n="$icon" /></span><span>{{ __('media.'.$label) }}</span>
                                </a>
                            @endforeach
                            @if ($canCreate)
                                <a href="{{ app(\App\Support\MediaRequests\HcGenerator::class)->url() }}" target="_blank" rel="noopener">
                                    <span class="appicon"><x-ic n="wand" /></span><span lang="en">HC Generator</span>
                                </a>
                            @endif
                        </div>
                    @endif
                    <div class="tb-rows">
                        <button type="button" x-data="{ dark: document.documentElement.dataset.theme ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches }"
                                x-on:click="dark = ! dark; document.documentElement.dataset.theme = dark ? 'dark' : 'light'; try { localStorage.setItem('mr-theme', dark ? 'dark' : 'light') } catch (e) {}">
                            <x-ic n="moon" x-show="! dark" /><x-ic n="sun" x-show="dark" x-cloak />{{ __('media.theme.dark') }}
                        </button>
                        <a href="{{ route('lang', $locale === 'ar' ? 'en' : 'ar') }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"><x-ic n="globe" />{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"><x-ic n="logout" class="flip" />{{ __('media.logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </nav>

    {{-- The request form, once for every page: the + above, the home screen and the request pages open it. --}}
    <livewire:media-requests.request-wizard />
    @stack('scripts')
    {{-- The proofreader, opened from the home screen card or a form field (event media-proofread-open). --}}
    <livewire:media-requests.proofreader-panel />
</body>
</html>
