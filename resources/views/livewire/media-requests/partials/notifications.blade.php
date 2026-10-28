{{--
    The person's notifications: one row per request (its latest update, and how many
    others), unread first by default, in sections by time. A click marks them read and
    opens the request on the home screen.
--}}
@php($sectionLabel = ['today' => __('media.bell.today'), 'week' => __('media.bell.week'), 'earlier' => __('media.bell.earlier')])

<div class="bellbar">
    <div class="seg" role="tablist">
        <button type="button" role="tab" wire:click="bellShow('unread')" @class(['on' => $bell['filter'] === 'unread']) :aria-selected="@js($bell['filter'] === 'unread')">
            {{ __('media.bell.unread') }}@if ($bell['unread'])<span class="n num">{{ $bell['unread'] }}</span>@endif
        </button>
        <button type="button" role="tab" wire:click="bellShow('all')" @class(['on' => $bell['filter'] === 'all']) :aria-selected="@js($bell['filter'] === 'all')">{{ __('media.bell.all') }}</button>
    </div>
    @if ($bell['unread'])
        <button type="button" class="btn-q readall" wire:click="markAllRead"><x-ic n="check" />{{ __('media.bell.readAll') }}</button>
    @endif
</div>

<div class="bellscroll">
@if ($bell['total'] === 0)
    <div class="wempty"><span class="ei"><x-ic n="bell" /></span><span>{{ $bell['filter'] === 'unread' ? __('media.bell.noneUnread') : __('media.bell.none') }}</span></div>
@else
    @foreach (['today', 'week', 'earlier'] as $part)
        @continue(empty($bell['sections'][$part]))
        <h3 class="bellsec">{{ $sectionLabel[$part] }}</h3>
        <ul class="wlist nlist">
            @foreach ($bell['sections'][$part] as $g)
                @php($n = $g['latest'])
                <li @class(['unread' => $g['unread'] > 0])>
                    <a href="#" wire:click.prevent="{{ $g['request'] ? 'openRequestNotes('.(int) $g['request'].')' : "openNotification('".$n->id."')" }}">
                        <span>{{ $n->data['body'][$locale] ?? '' }}</span>
                        <small class="sub">{{ $n->created_at->diffForHumans() }}@if ($g['count'] > 1) · {{ trans_choice('media.bell.moreUpdates', $g['count'] - 1, ['n' => $g['count'] - 1]) }}@endif</small>
                    </a>
                </li>
            @endforeach
        </ul>
    @endforeach
    @if ($bell['more'])
        <button type="button" class="btn btn-o btn-sm bellmore" wire:click="bellMore">{{ __('media.bell.more') }}</button>
    @endif
@endif
</div>
