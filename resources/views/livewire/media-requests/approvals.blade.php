<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.h.approvals') }}</h1>
            <p class="lead">{{ __('media.h.approvals.l') }}</p>
        </div>
    </div>

    @if ($requests->isEmpty())
        <div class="empty"><x-ic n="shield" /><b>{{ __('media.list.none.approvals') }}</b></div>
    @else
        <ul class="rcs">
            @foreach ($requests as $r)
                @include('livewire.media-requests.partials.row', ['r' => $r, 'mine' => true])
            @endforeach
        </ul>
    @endif
</div>
