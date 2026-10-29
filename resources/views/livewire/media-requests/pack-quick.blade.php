{{-- HC_PACK in a card or the side panel: type and the files appear here; tick and ask for them here. --}}
<div class="pq">
    <div class="search" style="max-width:none">
        <x-ic n="search" />
        <label for="pq-{{ $place }}" class="vh">{{ __('media.hp.ph') }}</label>
        <input id="pq-{{ $place }}" class="inp" type="search" autocomplete="off" wire:model.live.debounce.300ms="search"
               x-on:keydown.enter.prevent placeholder="{{ __('media.dash.packPh') }}" x-data="mrTypewriter(@js(__('media.ex.pack')))">
    </div>

    @if (! $searching)
        <div class="pq-groups">
            @foreach ($groups as [$key])
                <button type="button" class="chip" wire:click="$set('group', '{{ $key }}')">{{ __('media.hp.'.$key) }} <span class="num">{{ $counts[$key] ?? 0 }}</span></button>
            @endforeach
        </div>
    @else
        @if ($group !== '')
            <button type="button" class="chip on pq-in" wire:click="$set('group', '')"><x-ic n="x" />{{ __('media.hp.'.$group) }}</button>
        @endif
        @if ($files->isEmpty())
            <p class="acempty">{{ __('media.hp.none') }}</p>
        @else
            <ul class="pq-list">
                @foreach ($files as $file)
                    <li wire:key="pq-{{ $place }}-{{ md5($file['path']) }}">
                        <label @class(['on' => in_array($file['path'], $selected, true)])>
                            <input type="checkbox" value="{{ $file['path'] }}" wire:model.live="selected">
                            <span><b dir="auto">{{ $file['name'] }}</b><small dir="auto">{{ $file['dir'] }}</small></span>
                            <span class="tag soft">{{ strtoupper($file['ext']) }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    <div class="pq-foot">
        @if ($selected)
            <button type="button" class="btn btn-p btn-sm" wire:click="requestFiles" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="download" />{{ __('media.hp.sel', ['n' => count($selected)]) }} · {{ __('media.hp.get') }}</button>
        @endif
        <a class="btn-q" href="{{ route('media-requests.pack', array_filter(['q' => trim($search), 'group' => $group])) }}" wire:navigate>{{ __('media.dash.packAll') }}<x-ic n="arrow" class="flip" /></a>
    </div>
    @error('paths')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
</div>
