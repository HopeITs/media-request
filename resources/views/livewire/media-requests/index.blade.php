@php
    $locale = app()->getLocale();
    $first = auth()->user()->profile?->getAttribute('first_name_'.$locale) ?: auth()->user()->profile?->first_name_en;
    $tiles = [
        'action' => ['fl.action', 'arrow', true],
        'open' => ['fl.open', 'play', false],
        'done' => ['fl.done', 'check', false],
        'all' => ['fl.all', 'layers', false],
    ];
@endphp

<div>
    <section class="hello">
        <div>
            <h1><small>{{ __('media.home.hi', ['name' => $first]) }}</small>{{ $counts['action'] ? trans_choice('media.home.waiting', $counts['action'], ['n' => $counts['action']]) : __('media.home.clear') }}</h1>
            <p class="lead">{{ __('media.h.my.l') }}</p>
        </div>
        @activepermission('media-requests.create')
            <button type="button" class="btn btn-p btn-lg" x-on:click="$dispatch('media-wizard-open')"><x-ic n="plus" />{{ __('media.btn.new') }}</button>
        @endactivepermission
    </section>

    @activepermission('media-requests.create')
        <form class="askbar" x-data="{ text: '' }" x-on:submit.prevent="if (text.trim()) { $dispatch('media-wizard-open', { prompt: text }); text = '' }">
            <x-ic n="spark" class="spark" />
            <label for="ask" class="vh">{{ __('media.ai.title') }}</label>
            <input id="ask" x-model="text" type="text" autocomplete="off" placeholder="{{ __('media.ai.ph') }}" x-data="mrTypewriter(@js(__('media.ai.examples')))">
            <button class="btn btn-o" type="submit"><x-ic n="spark" />{{ __('media.ai.go') }}</button>
        </form>
    @endactivepermission

    <div class="stats" role="group" aria-label="{{ __('media.st.label') }}">
        @foreach ($tiles as $key => [$label, $icon, $hot])
            <button type="button" wire:click="setActiveTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['stat', 'hot' => $hot && $counts[$key] > 0])>
                <x-ic :n="$icon" @class(['flip' => $icon === 'arrow']) />
                <span class="sl">{{ __('media.'.$label) }}</span>
                <span class="sn num">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="filters">
        <div class="search">
            <x-ic n="search" />
            <label for="q" class="vh">{{ __('media.q.ph') }}</label>
            <input id="q" class="inp" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('media.q.ph') }}" x-data="mrTypewriter(@js(__('media.ex.search')))">
        </div>
        <label for="svc" class="vh">{{ __('media.q.svc') }}</label>
        <select id="svc" class="inp" style="width:auto;min-width:170px" wire:model.live="service">
            <option value="">{{ __('media.q.svc') }}</option>
            @foreach (config('media-requests.services') as $code => $svc)
                <option value="{{ $code }}">{{ __('media.svc.'.$svc['key']) }}</option>
            @endforeach
        </select>
        <span class="sub" wire:loading.delay><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span></span>
    </div>

    @if ($requests->isEmpty())
        <div class="empty">
            <x-ic n="inbox" />
            <b>{{ $counts['all'] ? __('media.list.none') : __('media.list.empty') }}</b>
            @unless ($counts['all']){{ __('media.list.empty.l') }}@endunless
        </div>
    @else
        <ul class="rcs">
            @foreach ($requests as $r)
                @include('livewire.media-requests.partials.row', ['r' => $r, 'mine' => $mine($r)])
            @endforeach
        </ul>
    @endif

</div>
