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
