{{-- For the person who asked: four stages (sent, approval, work, ready), where it is in one sentence, and the date.
     The team keeps the full seven-stage tracker on the request page. --}}
@props(['r', 'links' => true])
@php
    $at = $r->simpleStage();
    $due = $r->planned_for ?? $r->needed_by;
@endphp
<div {{ $attributes->class(['jr']) }}>
    <ol class="jr-steps">
        @foreach (['sent', 'approve', 'work', $r->isLinkedIn() ? 'publish' : 'ready'] as $i => $stage)
            @php($s = $i < $at ? 'done' : ($i === $at ? 'cur' : 'todo'))
            <li data-s="{{ $s }}" @if ($s === 'cur') aria-current="step" @endif>{{ __('media.jr.'.$stage) }}</li>
        @endforeach
    </ol>
    @if ($r->status === \App\Enums\MediaRequestStatus::Delivered && $r->isLinkedIn())
        <p class="progress act"><x-ic n="check" /><span>{{ __('media.act.postedDone') }}@if ($r->archive_link && $links) <a href="{{ $r->archive_link }}" target="_blank" rel="noopener noreferrer" x-on:click.stop>{{ __('media.act.seePost') }}</a>@endif</span></p>
    @elseif ($r->status === \App\Enums\MediaRequestStatus::Delivered)
        <p class="progress act"><x-ic n="check" /><span>{{ __($r->isPack() ? 'media.act.packDone' : 'media.act.delivered') }}</span></p>
    @else
        <x-media.progress :r="$r" />
    @endif
    @if ($due && $r->isOpen())
        <p class="jr-due"><x-ic n="cal" />{{ __($r->planned_for ? 'media.jr.planned' : 'media.lc.due') }} <b class="num">{{ $due->format('d-m-Y') }}</b></p>
    @endif
    {{ $slot }}
</div>
