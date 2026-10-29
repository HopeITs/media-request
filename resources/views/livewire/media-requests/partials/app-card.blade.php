{{-- A shortcut made big enough becomes a small card: its name (opens the page), one number and a few rows.
     The icon and this card are both drawn; the tile's own size picks one (container query in app.css). --}}
@php($d = $apps[$key] ?? ['rows' => []])
<div class="appcard" tabindex="-1" popover="manual">
    <button type="button" class="iconbtn wclose" x-on:click="closePeek()" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
    @if ($href)
        <a class="ach" href="{{ $href }}" @if ($external) target="_blank" rel="noopener" @else wire:navigate @endif>
            <span class="appicon"><x-ic :n="$cards->icon($key)" /></span><span @if ($key === 'app.generator') lang="en" @endif>{{ $label($key) }}</span><x-ic n="arrow" class="flip go" />
        </a>
    @else
        <span class="ach"><span class="appicon brand"><x-ic :n="$cards->icon($key)" /></span><span>{{ $label($key) }}</span></span>
    @endif

    @if ($key === 'app.new')
        <form class="acask" x-data="{ text: '' }" x-on:submit.prevent="if (! editing) { $dispatch('media-wizard-open', text.trim() ? { prompt: text } : {}); text = '' }">
            <label for="ask-app" class="vh">{{ __('media.ai.title') }}</label>
            <textarea id="ask-app" class="inp" rows="2" x-model="text" placeholder="{{ __('media.ai.ph') }}" x-data="mrTypewriter(@js(__('media.ai.examples')))"
                      x-on:keydown.enter.prevent="$el.form.requestSubmit()"></textarea>
            <button type="submit" class="btn btn-p btn-sm"><x-ic n="plus" />{{ __('media.dash.card.app.new') }}</button>
        </form>
    @elseif ($key === 'app.pack')
        <form method="GET" action="{{ route('media-requests.pack') }}" class="acask">
            <label for="pack-app" class="vh">{{ __('media.hp.ph') }}</label>
            <div class="search" style="max-width:none"><x-ic n="search" />
                <input id="pack-app" name="q" class="inp" type="search" placeholder="{{ __('media.dash.packPh') }}" x-data="mrTypewriter(@js(__('media.ex.pack')))">
            </div>
        </form>
    @else
        @isset($d['num'])
            <p class="acnum"><b class="num">{{ $d['num'] }}</b><span>{{ $d['unit'] ?? '' }}</span></p>
        @endisset
        @if ($d['rows'])
            <ul class="aclist">
                @foreach ($d['rows'] as $row)
                    <li>
                        @if (! empty($row['href']))
                            <a href="{{ $row['href'] }}" @if ($external) target="_blank" rel="noopener" @else wire:navigate @endif><span dir="auto">{{ $row['t'] }}</span>@isset($row['s'])<small>{{ $row['s'] }}</small>@endisset</a>
                        @else
                            <span><span dir="auto">{{ $row['t'] }}</span>@isset($row['s'])<small class="num">{{ $row['s'] }}</small>@endisset</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif (! empty($d['empty']))
            <p class="acempty">{{ $d['empty'] }}</p>
        @endif
    @endif
</div>
