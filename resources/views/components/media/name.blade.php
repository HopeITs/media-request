{{-- A request's name: what it is, in the person's words, then its archive code small under it
     (005DISC_ALP_Certificate). The code alone when there is no title; it may wrap after each "_". --}}
@props(['r'])
@php($naming = app(\App\Support\MediaRequests\MediaNaming::class))
@php($title = trim((string) $r->title))
@php($code = $r->isPack() ? 'HC_PACK' : $naming->requestPrefix($r))

<span {{ $attributes->merge(['class' => 'mrname']) }}>
    @if ($title !== '')
        <span class="mrt" dir="auto">{{ $title }}</span>
        <small class="mrc" dir="ltr">{!! str_replace('_', '_<wbr>', e($code)) !!}</small>
    @else
        <span class="mrt" dir="ltr">{!! str_replace('_', '_<wbr>', e($code)) !!}</span>
    @endif
</span>
