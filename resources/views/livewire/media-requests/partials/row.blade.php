{{-- One request as a card: what it is, where it is, who has it, and when it is needed. --}}
@php
    $mine ??= false;
    $catalog = app(\App\Support\MediaRequests\MediaCatalog::class);
    $svcIcon = ['001' => 'pen', '002' => 'camera', '003' => 'video', '000' => 'box'][$r->service] ?? 'file';
    $steps = $r->steps();
    $current = $r->currentStep();
    $locale = app()->getLocale();
@endphp

<li>
    <a href="{{ route('media-requests.show', $r) }}" wire:navigate @class(['rc', 'mine' => $mine])>
        <div class="rc-top">
            <div class="rc-tags">
                @if ($mine)<span class="tag act"><x-ic n="arrow" class="flip" />{{ __('media.tag.act') }}</span>@endif
                <x-media.status :r="$r" />
                @if ($r->rush_reason && $r->isOpen())<span class="tag rush"><x-ic n="flame" />{{ __('media.tag.rush') }}</span>@endif
            </div>
            <span class="rc-type"><x-ic :n="$svcIcon" />{{ __('media.svc.'.$catalog->serviceKey($r->service)) }}@unless ($r->isPack()) · {{ __('media.type.'.$r->type) }}@endunless</span>
        </div>

        <x-media.name :r="$r" class="rc-title" />

        <div class="rc-bot">
            <div class="meta">
                <span class="num ltr">{{ $r->reference }}</span>
                @if ($r->isOpen() && $r->owner())
                    <span><x-ic n="user" />{{ __('media.meta.with', ['who' => '']) }}<b class="owner">{{ $r->ownerName() }}</b></span>
                @endif
                @if ($r->needed_by)
                    <span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>
                @endif
            </div>
            @if (! in_array($r->status, ['rejected', 'cancelled'], true))
                <div class="segs" aria-hidden="true">
                    @foreach ($steps as $i => $step)
                        <i data-s="{{ $i < $current ? 'done' : ($i === $current ? 'cur' : 'todo') }}"></i>
                    @endforeach
                </div>
            @endif
        </div>
    </a>
</li>
