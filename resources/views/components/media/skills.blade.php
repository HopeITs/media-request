{{--
    A team member's specialties (from the position title), those they do on need
    (outlined), and what else they do (interviews, print, …).
--}}
@props(['e' => null, 'title' => null])
@php($team = app(\App\Support\MediaRequests\MediaTeam::class))
@php($probe = $e ?? new \App\Models\Employee(['position_title' => (string) $title]))
<span {{ $attributes->class(['skills']) }}>
    @foreach ($team->skillsOf($probe) as $s)<span class="chip"><x-ic :n="config('media-requests.specialties.'.$s.'.icon')" />{{ __('media.spec.'.$s) }}</span>@endforeach
    @foreach ($team->extraSkillsOf($probe) as $s)<span class="chip onneed" title="{{ __('media.spec.onNeed') }}"><x-ic :n="config('media-requests.specialties.'.$s.'.icon')" />{{ __('media.spec.'.$s) }} · {{ __('media.spec.onNeedShort') }}</span>@endforeach
    @foreach ($team->extrasOf($probe) as $x)<span class="chip soft"><x-ic :n="config('media-requests.extras.'.$x.'.icon')" />{{ __('media.extra.'.$x) }}</span>@endforeach
</span>
