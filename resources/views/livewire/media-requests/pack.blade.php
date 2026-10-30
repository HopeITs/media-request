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
        @if ($manages)
            <button type="button" class="btn btn-o" wire:click="$toggle('adding')" :aria-expanded="@js($adding)"><x-ic n="plus" />{{ __('media.hp.m.add') }}</button>
        @endif
    </div>

    @if (session('packDone'))
        <p class="okline" role="status"><x-ic n="check" />{{ session('packDone') }}</p>
    @endif

    {{-- The media manager adds a file: where it goes (a group's folder, and a sub-folder if wanted) and the file. --}}
    @if ($manages && $adding)
        <form class="card pm-add" wire:submit="addFile">
            <h2>{{ __('media.hp.m.add') }}</h2>
            <div class="pm-grid">
                <div class="field">
                    <label for="pm-g">{{ __('media.hp.group') }}</label>
                    <select id="pm-g" class="inp" wire:model="newGroup" @error('newGroup') aria-invalid="true" @enderror>
                        <option value="">{{ __('media.o.select') }}</option>
                        @foreach ($groups as [$key, $dir])
                            <option value="{{ $key }}">{{ __('media.hp.'.$key) }} · {{ $dir }}</option>
                        @endforeach
                    </select>
                    @error('newGroup')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="pm-f">{{ __('media.hp.m.folder') }}</label>
                    <input id="pm-f" class="inp ltr" type="text" wire:model="newFolder" maxlength="120" placeholder="001 HC">
                    <p class="hint">{{ __('media.hp.m.folderHint') }}</p>
                </div>
                <div class="field">
                    <label for="pm-n">{{ __('media.hp.m.file') }}</label>
                    <input id="pm-n" class="inp" type="file" wire:model="newFile" accept="{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}" @error('newFile') aria-invalid="true" @enderror>
                    @error('newFile')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="btnline">
                <button type="button" class="btn btn-g" wire:click="$set('adding', false)">{{ __('media.confirm.back') }}</button>
                <button type="submit" class="btn btn-p" wire:loading.attr="data-loading" wire:target="addFile,newFile"><span class="spin"></span><x-ic n="plus" />{{ __('media.hp.m.addBtn') }}</button>
            </div>
        </form>
    @endif

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
                <li wire:key="f-{{ md5($file['path']) }}" @class(['pm-row' => $manages])>
                    <label @class(['fr', 'on' => in_array($file['path'], $selected, true)])>
                        <input type="checkbox" value="{{ $file['path'] }}" wire:model.live="selected">
                        <span class="fn"><b>{{ $file['name'] }}</b><small>{{ $file['dir'] }}@if ($file['older']) · {{ trans_choice('media.hp.m.older', $file['older'], ['n' => $file['older']]) }}@endif</small></span>
                        @if ($file['changed'])<span class="tag soft" title="{{ $file['changed']->format('d-m-Y') }}">{{ __('media.hp.m.updated') }}</span>@endif
                        <span class="tag soft">{{ strtoupper($file['ext']) }}</span>
                        <span class="sub num">{{ $size($file['size']) }}</span>
                    </label>
                    @if ($manages)
                        <span class="pm-tools">
                            @if ($file['id'])
                                <a class="iconbtn" href="{{ route('media-requests.pack.file', $file['id']) }}" title="{{ __('media.hp.m.download') }}" aria-label="{{ __('media.hp.m.download') }}: {{ $file['name'] }}"><x-ic n="download" /></a>
                            @endif
                            <button type="button" class="iconbtn" wire:click="startReplace(@js($file['path']))" title="{{ __('media.hp.m.replace') }}" aria-label="{{ __('media.hp.m.replace') }}: {{ $file['name'] }}"><x-ic n="reset" /></button>
                            <button type="button" class="iconbtn" title="{{ __('media.hp.m.remove') }}" aria-label="{{ __('media.hp.m.remove') }}: {{ $file['name'] }}"
                                    x-data x-on:click="mrConfirm(@js(__('media.hp.m.removeConfirm', ['name' => $file['name']])), { ok: @js(__('media.hp.m.remove')), cancel: @js(__('media.confirm.back')), danger: true }).then((yes) => yes && $wire.removeFile(@js($file['path'])))"><x-ic n="x" /></button>
                        </span>
                        @if ($replacing === $file['path'])
                            <form class="pm-replace" wire:submit="replaceFile">
                                <label for="pm-r-{{ md5($file['path']) }}">{{ __('media.hp.m.replaceWith') }}</label>
                                <input id="pm-r-{{ md5($file['path']) }}" class="inp" type="file" wire:model="replacement" accept="{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}">
                                <button type="button" class="btn btn-g btn-sm" wire:click="cancelReplace">{{ __('media.confirm.back') }}</button>
                                <button type="submit" class="btn btn-p btn-sm" wire:loading.attr="data-loading" wire:target="replaceFile,replacement"><span class="spin"></span>{{ __('media.hp.m.replace') }}</button>
                                @error('replacement')<p class="err" style="flex-basis:100%"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </form>
                        @endif
                    @endif
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
