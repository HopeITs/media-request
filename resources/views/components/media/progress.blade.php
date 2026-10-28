@props(['r', 'forRequester' => true])
@php
    use App\Enums\MediaRequestStatus as S;

    // Who has the request now and what they are doing, in one plain sentence, for the
    // requester and their program manager: "Fadi took your request and is working on it".
    $who = $r->assignee?->nameForLocale(app()->getLocale());
    $key = match ($r->status) {
        S::ProgramApproval => 'pm',
        S::MediaApproval => 'mm',
        S::ToClaim => 'claim',
        S::Assigned => 'taken',
        S::InProgress => 'working',
        S::InternalReview => 'check',
        S::RequesterReview => $forRequester ? 'reviewYou' : 'review',
        S::Production => 'production',
        S::Delivery => 'delivery',
        S::NeedsInfo => $forRequester ? 'infoYou' : 'info',
        S::OnHold => 'hold',
        default => null,
    };
    $icon = ['working' => 'pen', 'taken' => 'user', 'infoYou' => 'alert', 'info' => 'alert', 'reviewYou' => 'eye', 'review' => 'eye',
        'production' => 'printer', 'delivery' => 'box', 'claim' => 'inbox', 'hold' => 'pause', 'pm' => 'shield', 'mm' => 'shield', 'check' => 'eye'][$key] ?? 'clock';
@endphp
@if ($key)
    <p {{ $attributes->class(['progress', 'act' => in_array($key, ['infoYou', 'reviewYou'], true), 'live' => $key === 'working']) }}>
        <x-ic :n="$icon" />
        <span>{{ __('media.progress.'.$key, ['name' => $who ?? __('media.progress.someone')]) }}@if ($r->status_changed_at && in_array($key, ['working', 'taken', 'infoYou', 'info'], true)) <small class="sub">· {{ $r->status_changed_at->diffForHumans() }}</small>@endif</span>
    </p>
@endif
