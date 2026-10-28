@php($locale = app()->getLocale())

<div>
    <div class="ph">
        <h1>{{ __('media.bell.title') }}</h1>
        @if ($items->whereNull('read_at')->isNotEmpty())
            <button type="button" class="btn btn-o" wire:click="markAllRead"><x-ic n="check" />{{ __('media.bell.readAll') }}</button>
        @endif
    </div>

    @if ($items->isEmpty())
        <div class="empty"><x-ic n="bell" /><b>{{ __('media.bell.none') }}</b></div>
    @else
        <ul class="list">
            @foreach ($items as $n)
                <li>
                    <a href="{{ isset($n->data['media_request_id']) ? route('media-requests.show', $n->data['media_request_id']) : (parse_url($n->data['url'] ?? '', PHP_URL_PATH) ?: '#') }}" wire:navigate class="fr" style="text-decoration:none;align-items:flex-start">
                        <span class="av sm {{ $n->read_at ? 'soft' : '' }}" @unless ($n->read_at) style="background:var(--brand)" @endunless><x-ic n="bell" /></span>
                        <span style="flex:1;min-width:0">
                            <span style="display:block;{{ $n->read_at ? '' : 'font-weight:700' }}">{{ $n->data['body'][$locale] ?? '' }}</span>
                            <small class="sub">{{ $n->created_at->diffForHumans() }}</small>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
