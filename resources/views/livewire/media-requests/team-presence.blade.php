{{-- The media team, online first. Refreshed every minute while on screen. --}}
<div class="presence" wire:poll.visible.60s>
    <p class="pres-h"><span>{{ __('media.dash.pres.title') }}</span><span class="num">{{ __('media.dash.pres.count', ['n' => $people->where('online', true)->count(), 'all' => $people->count()]) }}</span></p>
    <ul>
        @foreach ($people as $p)
            <li wire:key="pr-{{ $p['id'] }}" @class(['on' => $p['online']])>
                <i class="dot" aria-hidden="true"></i>
                <span dir="auto">{{ $p['name'] }}</span>
                <small>{{ $p['online'] ? __('media.dash.pres.online') : ($p['seen'] ? __('media.dash.pres.seen', ['when' => $p['seen']]) : __('media.dash.pres.offline')) }}</small>
            </li>
        @endforeach
    </ul>
</div>
