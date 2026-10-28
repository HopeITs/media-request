{{-- Status as icon + words on neutrals (brand rule: no status colours). Red only for "late". --}}
@props(['r', 'late' => true])

@php
    $S = \App\Enums\MediaRequestStatus::class;
    $icon = [
        $S::ProgramApproval => 'clock', $S::MediaApproval => 'clock', $S::ToClaim => 'user', $S::Assigned => 'user',
        $S::InProgress => 'play', $S::InternalReview => 'shield', $S::RequesterReview => 'eye', $S::NeedsInfo => 'alert',
        $S::OnHold => 'pause', $S::Production => 'printer', $S::Delivery => 'box', $S::Delivered => 'check',
        $S::Rejected => 'x', $S::Cancelled => 'x',
    ][$r->status] ?? 'clock';
    $tone = $S::tone($r->status);
@endphp

<span {{ $attributes->merge(['class' => 'chip '.($tone === 'wait' ? '' : $tone)]) }}><x-ic :n="$icon" />{{ $S::label($r->status, $r->type) }}</span>
@if ($late && $r->isLate())
    <span class="tag late"><x-ic n="flame" />{{ __('media.tag.late') }}</span>
@endif
{{-- Delivered the day it was asked for: the strongest badge. Otherwise, before the needed-by day: early. --}}
@if ($r->sameDay())
    <span class="tag boost" title="{{ __('media.boost.title') }}"><x-ic n="rocket" />{{ __('media.boost.tag') }}</span>
@elseif ($r->deliveryTiming() === 'early')
    <span class="tag early" title="{{ trans_choice('media.early.title', $r->daysEarly(), ['n' => $r->daysEarly()]) }}"><x-ic n="bolt" />{{ trans_choice('media.early.tag', $r->daysEarly(), ['n' => $r->daysEarly()]) }}</span>
@endif
