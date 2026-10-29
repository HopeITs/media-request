@php
    $size = fn (int $b) => $b < 1048576 ? max(1, round($b / 1024)).' KB' : number_format($b / 1048576, 1).' MB';
    $groupIcon = ['projects' => 'image', 'donors' => 'image', 'parish' => 'image', 'cop' => 'image', 'orgs' => 'image', 'guides' => 'file',
        'id' => 'user', 'signature' => 'pen', 'letterhead' => 'file', 'rollup' => 'layers', 'cert' => 'file', 'social' => 'image',
        'wallpaper' => 'image', 'word' => 'file', 'qr' => 'box'];
@endphp

<div>
    <div class="ph">
        <div>
            <h1>HC_PACK</h1>
            <p class="lead">{{ __('media.hp.lead') }}</p>
        </div>
    </div>

    <div class="filters">
        <div class="search" style="max-width:none">
            <x-ic n="search" />
            <label for="hp-q" class="vh">{{ __('media.hp.ph') }}</label>
            <input id="hp-q" class="inp" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('media.hp.ph') }}" x-data="mrTypewriter(@js(__('media.ex.pack')))">
        </div>
        <label for="hp-g" class="vh">{{ __('media.hp.group') }}</label>
        <select id="hp-g" class="inp" style="width:auto;min-width:200px" wire:model.live="group">
            <option value="">{{ __('media.chip.all') }}</option>
            @foreach ($groups as [$key])
                <option value="{{ $key }}">{{ __('media.hp.'.$key) }} ({{ $counts[$key] ?? 0 }})</option>
            @endforeach
        </select>
        <span class="sub" wire:loading.delay><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span></span>
    </div>

    @if (! $searching)
        <div class="groups">
            @foreach ($groups as [$key])
                <button type="button" class="group" wire:click="$set('group', '{{ $key }}')">
                    <x-ic :n="$groupIcon[$key] ?? 'box'" />
                    <b><x-media.bi :k="'media.hp.'.$key" col /></b>
                    <span class="sub">{{ __('media.hp.count', ['n' => $counts[$key] ?? 0]) }}</span>
                </button>
            @endforeach
        </div>
    @elseif ($files->isEmpty())
        <div class="empty"><x-ic n="search" /><b>{{ __('media.hp.none') }}</b></div>
    @else
        <p class="sub" style="margin-bottom:8px">{{ __('media.hp.count', ['n' => $files->count()]) }}</p>
        <ul class="list">
            @foreach ($files as $file)
                <li wire:key="f-{{ md5($file['path']) }}">
                    <label @class(['fr', 'on' => in_array($file['path'], $selected, true)])>
                        <input type="checkbox" value="{{ $file['path'] }}" wire:model.live="selected">
                        <span class="fn"><b>{{ $file['name'] }}</b><small>{{ $file['dir'] }}</small></span>
                        <span class="tag soft">{{ strtoupper($file['ext']) }}</span>
                        <span class="sub num">{{ $size($file['size']) }}</span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($chosen->isNotEmpty())
        <form class="packbar" wire:submit="requestFiles">
            <b><x-ic n="check" />{{ __('media.hp.sel', ['n' => $chosen->count()]) }}</b>
            <label for="hp-note" class="vh">{{ __('media.hp.note') }}</label>
            <input id="hp-note" class="inp" type="text" wire:model="note" placeholder="{{ __('media.hp.note') }}">
            <button type="button" class="btn btn-g" wire:click="$set('selected', [])">{{ __('media.hp.clear') }}</button>
            <button type="submit" class="btn btn-p" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="download" />{{ __('media.hp.get') }}</button>
            @error('paths')<p class="err" style="flex-basis:100%"><x-ic n="alert" />{{ $message }}</p>@enderror
        </form>
    @endif
</div>
