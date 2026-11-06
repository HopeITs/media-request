{{--
    The team members to give a request to. For a shoot: the team of the place first,
    then everyone else as an exception (config media-requests.coverage). Each with the
    work already in their hands, so the least busy one is plain to see.
--}}
@php($busy = fn ($m) => ' · '.trans_choice('media.fm.load', $load[$m->id] ?? 0, ['n' => $load[$m->id] ?? 0]))
@if ($team->onSite($r))
    @php($inArea = $members->filter(fn ($m) => $team->coversArea($m, $r)))
    @if ($inArea->isNotEmpty())
        <optgroup label="{{ __('media.cov.area') }}">
            @foreach ($inArea as $m)<option value="{{ $m->id }}">{{ $name($m) }} · {{ $m->city?->{$locale === 'ar' ? 'name_ar' : 'name_en'} }}{{ $busy($m) }}</option>@endforeach
        </optgroup>
    @endif
    <optgroup label="{{ __('media.cov.other') }}">
        @foreach ($members->reject(fn ($m) => $team->coversArea($m, $r)) as $m)<option value="{{ $m->id }}">{{ $name($m) }} · {{ $m->city?->{$locale === 'ar' ? 'name_ar' : 'name_en'} ?? '—' }}{{ $busy($m) }}</option>@endforeach
    </optgroup>
@else
    @foreach ($members as $m)<option value="{{ $m->id }}">{{ $name($m) }}@if ($team->hasSkill($m, $specialty)) · {{ __('media.spec.'.$specialty) }}@endif{{ $busy($m) }}</option>@endforeach
@endif
