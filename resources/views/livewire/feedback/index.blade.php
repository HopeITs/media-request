@php
    $locale = app()->getLocale();
    $face = [1 => ['frown', 'fb-bad'], 2 => ['meh', 'fb-meh'], 3 => ['smile', 'fb-good']];
@endphp

<div x-data="{ shot: null }" x-on:keydown.escape.window="shot = null">
    {{-- A screenshot, large: closed with ×, Esc or a click beside it. --}}
    <template x-teleport="body">
        <div class="fb-view" x-show="shot" x-cloak x-transition.opacity x-on:click.self="shot = null" role="dialog" aria-modal="true" aria-label="{{ __('media.fb.col.shot') }}">
            <button type="button" class="iconbtn fb-view-x" x-on:click="shot = null" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
            <img :src="shot" alt="{{ __('media.fb.col.shot') }}">
        </div>
    </template>
    <div class="ph">
        <div>
            <h1>{{ __('media.fb.title') }}</h1>
            <p class="lead">{{ __('media.fb.lead') }}</p>
        </div>
    </div>

    {{-- How each action is rated, worst first. --}}
    @if ($byAction->isNotEmpty())
        <section class="card" style="margin-bottom:18px">
            <h2>{{ __('media.fb.byAction') }}</h2>
            <ul class="fb-actions">
                @foreach ($byAction as $a)
                    <li>
                        <b dir="auto">{{ \App\Support\BetaFeedback::actionLabel($a->action) }}</b>
                        <span class="fb-bar" aria-label="{{ __('media.fb.split', ['good' => $a->good, 'meh' => $a->meh, 'bad' => $a->bad]) }}">
                            @if ($a->good)<i class="fb-good" style="flex:{{ $a->good }}"></i>@endif
                            @if ($a->meh)<i class="fb-meh" style="flex:{{ $a->meh }}"></i>@endif
                            @if ($a->bad)<i class="fb-bad" style="flex:{{ $a->bad }}"></i>@endif
                        </span>
                        <span class="sub num">{{ __('media.fb.split', ['good' => $a->good, 'meh' => $a->meh, 'bad' => $a->bad]) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="tabs" role="tablist" style="margin-bottom:14px">
        @foreach (['open' => 'alert', 'report' => 'msg', 'rating' => 'check', 'all' => 'list'] as $key => $icon)
            <button type="button" role="tab" wire:click="setShow('{{ $key }}')" aria-selected="{{ $show === $key ? 'true' : 'false' }}">
                <x-ic :n="$icon" />{{ __('media.fb.show.'.$key) }}@if (isset($counts[$key]))<span class="cnt num">{{ $counts[$key] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="tablewrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">{{ __('media.fb.col.when') }}</th>
                    <th scope="col">{{ __('media.fb.col.who') }}</th>
                    <th scope="col">{{ __('media.fb.col.what') }}</th>
                    <th scope="col">{{ __('media.fb.col.page') }}</th>
                    <th scope="col">{{ __('media.fb.col.note') }}</th>
                    <th scope="col">{{ __('media.fb.col.shot') }}</th>
                    <th scope="col"><span class="vh">{{ __('media.fb.col.done') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $f)
                    <tr wire:key="fb-{{ $f->id }}" @class(['fb-done' => $f->done_at])>
                        <td data-l="{{ __('media.fb.col.when') }}" class="num" style="white-space:nowrap">{{ $f->created_at->format('d-m-Y H:i') }}</td>
                        <td data-l="{{ __('media.fb.col.who') }}"><b>{{ $f->user?->profile?->nameForLocale($locale) ?? $f->user?->name ?? '—' }}</b></td>
                        <td data-l="{{ __('media.fb.col.what') }}">
                            @if ($f->kind === 'report')
                                <span class="tag rush"><x-ic n="alert" />{{ __('media.fb.report') }}</span>
                            @else
                                <span class="fb-face {{ $face[$f->rating][1] ?? '' }}"><x-ic :n="$face[$f->rating][0] ?? 'meh'" />{{ __('media.fb.rate.'.$f->rating) }}</span>
                            @endif
                            @if ($f->action)<div class="sub" dir="auto" style="margin-top:4px">{{ \App\Support\BetaFeedback::actionLabel($f->action) }}</div>@endif
                        </td>
                        <td data-l="{{ __('media.fb.col.page') }}"><a href="{{ $f->url }}" class="ltr" style="overflow-wrap:anywhere" data-ws-skip target="_blank" rel="noopener">{{ $f->url }}</a></td>
                        <td data-l="{{ __('media.fb.col.note') }}" dir="auto" style="white-space:pre-line;overflow-wrap:anywhere;min-width:200px">{{ $f->note ?: '—' }}@if ($f->fix)<div class="fb-fix"><x-ic n="check" /><span>{{ $f->fix }}</span></div>@endif</td>
                        <td data-l="{{ __('media.fb.col.shot') }}">
                            @if ($f->screenshot)
                                {{-- Opens large over this page (the viewer below), so the page is never left. --}}
                                <a href="{{ route('feedback.shot', $f) }}" data-ws-skip class="fb-shot" x-on:click.prevent="shot = $el.href">
                                    <img src="{{ route('feedback.shot', $f) }}" alt="{{ __('media.fb.col.shot') }}" loading="lazy">
                                </a>
                            @else
                                <span class="sub">—</span>
                            @endif
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm {{ $f->done_at ? 'btn-o' : 'btn-g' }}" wire:click="toggleDone({{ $f->id }})" aria-pressed="{{ $f->done_at ? 'true' : 'false' }}">
                                <x-ic n="check" />{{ $f->done_at ? __('media.fb.done') : __('media.fb.markDone') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;padding:32px" class="sub">{{ __('media.fb.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($rows->hasPages())
        <div class="btnline" style="justify-content:center;margin-top:16px">
            @if (! $rows->onFirstPage())<button type="button" class="btn btn-o btn-sm" wire:click="previousPage"><x-ic n="back" class="flip" /></button>@endif
            <span class="sub" style="align-self:center">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
            @if ($rows->hasMorePages())<button type="button" class="btn btn-o btn-sm" wire:click="nextPage"><x-ic n="arrow" class="flip" /></button>@endif
        </div>
    @endif
</div>
