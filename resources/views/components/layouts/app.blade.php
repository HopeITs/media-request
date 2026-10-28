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
        ['media-requests.pack', 'nav.pack', 'box', $user->hasActivePermission('media-requests.create'), ['media-requests.pack']],
        ['media-requests.log', 'nav.log', 'list', $isMediaTeam && $user->hasActivePermission('media-requests.view-log'), ['media-requests.log']],
        ['media-requests.assistant', 'nav.assistant', 'spark', $isMediaTeam && $user->hasActivePermission('media-requests.view-log'), ['media-requests.assistant']],
        ['people.index', 'nav.people', 'users', $user->hasActivePermission('people.manage'), ['people.index']],
    ], fn ($item) => $item[3]);
    // People who only ask for work have the home screen only: no page links, and the bell is a card there.
    $requesterOnly = $team->isRequesterOnly($user);
    // The home screen uses the whole width of a big screen; other pages keep a reading width.
    $wide = request()->routeIs('media-requests.home');
    if ($requesterOnly) {
        $nav = [];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
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

            <nav class="nav" aria-label="{{ __('media.app') }}">
                @foreach ($nav as [$route, $label, $icon, , $active])
                    <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs(...$active)) aria-current="page" @endif>
                        <x-ic :n="$icon" />{{ __('media.'.$label) }}
                    </a>
                @endforeach
            </nav>

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
    @stack('scripts')
    {{-- The proofreader, opened from the home screen card or a form field (event media-proofread-open). --}}
    <livewire:media-requests.proofreader-panel />
</body>
</html>
