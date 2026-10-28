{{-- The person's notifications: a click marks it read and opens its request on the home screen. --}}
@if ($bell->isEmpty())
    <div class="wempty"><span class="ei"><x-ic n="bell" /></span><span>{{ __('media.bell.none') }}</span></div>
@else
    @if ($bell->whereNull('read_at')->isNotEmpty())
        <button type="button" class="btn-q readall" wire:click="markAllRead"><x-ic n="check" />{{ __('media.bell.readAll') }}</button>
    @endif
    <ul class="wlist nlist">
        @foreach ($bell as $n)
            <li @class(['unread' => ! $n->read_at])>
                <a href="#" wire:click.prevent="openNotification('{{ $n->id }}')">
                    <span>{{ $n->data['body'][$locale] ?? '' }}</span>
                    <small class="sub">{{ $n->created_at->diffForHumans() }}</small>
                </a>
            </li>
        @endforeach
    </ul>
@endif
