{{-- A request's name: what it is, in the person's words. The media team also sees its archive code small
     under it (005DISC_ALP_Certificate), and it may wrap after each "_". With no title (old requests): the
     code for the team, the request number for everyone else. --}}
@props(['r'])
@php($naming = app(\App\Support\MediaRequests\MediaNaming::class))
@php($title = trim((string) $r->title))
@php($code = $r->isPack() ? 'HC_PACK' : $naming->requestPrefix($r))
@php($seesCode = $r->isPack() || app(\App\Support\MediaRequests\MediaTeam::class)->seesArchive(auth()->user()))

<span {{ $attributes->merge(['class' => 'mrname']) }}>
    @if ($title !== '')
        <span class="mrt" dir="auto">{{ $title }}</span>
        @if ($seesCode)<small class="mrc" dir="ltr">{!! str_replace('_', '_<wbr>', e($code)) !!}</small>@endif
    @elseif ($seesCode)
        <span class="mrt" dir="ltr">{!! str_replace('_', '_<wbr>', e($code)) !!}</span>
    @else
        <span class="mrt" dir="ltr">{{ $r->reference }}</span>
    @endif
</span>
