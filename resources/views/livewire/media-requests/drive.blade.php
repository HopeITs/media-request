{{--
    The files as folders: program / governorate / request / files. Folders show how many
    requests and files are inside; a request shows what was attached and every version sent.
--}}
<div class="drive">
    <div class="ph">
        <div>
            <h1>{{ __('media.nav.drive') }}</h1>
            <p class="lead">{{ __('media.drive.lead') }}</p>
        </div>
        <div class="search" style="max-width:340px;width:100%">
            <x-ic n="search" />
            <label for="drive-q" class="vh">{{ __('media.drive.search') }}</label>
            <input id="drive-q" class="inp" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('media.drive.search') }}">
        </div>
    </div>

    {{-- Where you are --}}
    <nav class="crumbs" aria-label="{{ __('media.drive.where') }}">
        <button type="button" wire:click="up('root')" @class(['on' => ! $programObj])><x-ic n="home" />{{ __('media.drive.all') }}</button>
        @if ($programObj)
            <x-ic n="arrow" class="flip sep" />
            <button type="button" wire:click="up('program')" @class(['on' => ! $cityObj]) class="ltr">{{ trim($programObj->code.' '.$programObj->slug) }}</button>
        @endif
        @if ($cityObj)
            <x-ic n="arrow" class="flip sep" />
            <button type="button" wire:click="up('city')" @class(['on' => ! $requestObj]) class="ltr">{{ trim($cityObj->code.' '.$cityObj->slug) }}</button>
        @endif
        @if ($requestObj)
            <x-ic n="arrow" class="flip sep" />
            <span class="on ltr">{{ $requestObj->reference }}</span>
        @endif
    </nav>

    @if (trim($search) !== '')
        {{-- Search across all folders --}}
        <ul class="dgrid">
            @forelse ($found as $r)
                <li><a href="{{ route('media-requests.drive', ['p' => $r->program_id, 'c' => $r->city_id, 'r' => $r->id]) }}" wire:navigate class="dfolder">
                    <span class="dico"><x-ic n="inbox" /></span>
                    <span class="dname ltr">{{ $r->reference }}</span>
                    <span class="dsub ltr">{{ $r->displayName() }}</span>
                    <span class="dsub ltr">{{ trim($r->program?->code.' '.$r->program?->slug) }} / {{ trim($r->city?->code.' '.$r->city?->slug) }}</span>
                </a></li>
            @empty
                <li class="sub">{{ __('media.drive.none') }}</li>
            @endforelse
        </ul>
    @elseif ($requestObj)
        {{-- Inside a request: its links, what was attached, and every version sent --}}
        @php($r = $requestObj)
        <div class="dreq">
            <div class="dreq-h">
                <h2 class="ltr">{{ $r->displayName() }}</h2>
                <x-media.status :r="$r" />
                <a href="{{ route('media-requests.show', $r) }}" wire:navigate class="btn btn-o btn-sm"><x-ic n="eye" />{{ __('media.drive.openRequest') }}</a>
            </div>

            @php($links = array_filter(['draft' => $r->draft_link, 'result' => $r->result_link, 'archive' => $r->archive_link]))
            @if ($links)
                <ul class="dgrid">
                    @foreach ($links as $kind => $href)
                        <li><a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="dfolder dlink">
                            <span class="dico"><x-ic n="link" /></span>
                            <span class="dname">{{ __('media.link.'.$kind) }}</span>
                            <span class="dsub ltr">{{ parse_url($href, PHP_URL_HOST) }}</span>
                        </a></li>
                    @endforeach
                </ul>
            @endif

            <x-media.results :r="$r" />

            @if ($r->getMedia('attachments')->isNotEmpty())
                <div class="resgal">
                    <p class="resgal-h">{{ __('media.drive.attached') }}</p>
                    <ul>
                        @foreach ($r->getMedia('attachments') as $m)
                            @php($viewable = in_array($m->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true))
                            <li><a href="{{ route('media-requests.files', $viewable ? ['media' => $m, 'view' => 1] : $m) }}" @if ($viewable) target="_blank" rel="noopener" @endif>
                                @if (str_starts_with((string) $m->mime_type, 'image/') && $m->mime_type !== 'image/svg+xml')
                                    <img src="{{ route('media-requests.files', ['media' => $m, 'view' => 1]) }}" alt="{{ $m->file_name }}" loading="lazy">
                                @else
                                    <span class="rpi"><x-ic n="file" /><small class="ltr">{{ strtoupper(pathinfo($m->file_name, PATHINFO_EXTENSION)) }}</small></span>
                                @endif
                                <span class="rpn ltr">{{ $m->file_name }}</span>
                            </a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! $links && $r->media->isEmpty())
                <div class="wempty" style="min-height:160px"><span class="ei"><x-ic n="file" /></span><span>{{ __('media.drive.emptyRequest') }}</span></div>
            @endif
        </div>
    @else
        {{-- Folders --}}
        <ul class="dgrid">
            @forelse ($folders as $f)
                <li><button type="button" class="dfolder" wire:click="open('{{ $f['level'] }}', {{ $f['id'] }})">
                    <span class="dico"><x-ic :n="$f['level'] === 'request' ? 'inbox' : 'layers'" /></span>
                    <span class="dname ltr">{{ $f['name'] }}</span>
                    @if (! empty($f['sub']))<span class="dsub ltr">{{ $f['sub'] }}</span>@endif
                    <span class="dsub">
                        @isset($f['requests']){{ trans_choice('media.drive.nRequests', $f['requests'], ['n' => $f['requests']]) }} · @endisset
                        {{ trans_choice('media.drive.nFiles', $f['files'], ['n' => $f['files']]) }}
                    </span>
                </button></li>
            @empty
                <li class="sub">{{ __('media.drive.none') }}</li>
            @endforelse
        </ul>
    @endif
</div>
