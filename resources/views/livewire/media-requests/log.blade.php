@php($locale = app()->getLocale())

<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.h.log') }}</h1>
            <p class="lead">{{ __('media.h.log.l') }}</p>
        </div>
        @activepermission('media-requests.export')
            <button type="button" class="btn btn-o" wire:click="exportCsv"><x-ic n="download" />{{ __('media.lg.export') }}</button>
        @endactivepermission
    </div>

    <div class="filters">
        <div class="search">
            <x-ic n="search" />
            <label for="lg-q" class="vh">{{ __('media.lg.ph') }}</label>
            <input id="lg-q" class="inp" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('media.lg.ph') }}">
        </div>
        <label for="lg-e" class="vh">{{ __('media.lg.col.what') }}</label>
        <select id="lg-e" class="inp" style="width:auto;min-width:180px" wire:model.live="event">
            <option value="">{{ __('media.chip.all') }}</option>
            @foreach ($events as $e)<option value="{{ $e }}">{{ __('media.lg.'.$e) }}</option>@endforeach
        </select>
        <label class="sub" style="display:flex;align-items:center;gap:6px">{{ __('media.lg.from') }}<input type="date" class="inp" style="width:auto" wire:model.live="from"></label>
        <label class="sub" style="display:flex;align-items:center;gap:6px">{{ __('media.lg.to') }}<input type="date" class="inp" style="width:auto" wire:model.live="to"></label>
        @if ($search || $event || $from || $to)
            <button type="button" class="btn-q" wire:click="resetFilters"><x-ic n="reset" />{{ __('media.lg.reset') }}</button>
        @endif
    </div>

    <div class="tablewrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">{{ __('media.lg.col.when') }}</th>
                    <th scope="col">{{ __('media.lg.col.who') }}</th>
                    <th scope="col">{{ __('media.lg.col.what') }}</th>
                    <th scope="col">{{ __('media.lg.col.req') }}</th>
                    <th scope="col">{{ __('media.lg.col.details') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $a)
                    <tr wire:key="a-{{ $a->id }}">
                        <td data-l="{{ __('media.lg.col.when') }}" class="num" style="white-space:nowrap">{{ $a->created_at->format('d-m-Y H:i') }}</td>
                        <td data-l="{{ __('media.lg.col.who') }}"><b>{{ $a->causer?->profile?->nameForLocale($locale) ?? '—' }}</b></td>
                        <td data-l="{{ __('media.lg.col.what') }}"><span class="lact">{{ __('media.lg.'.$a->description) }}</span></td>
                        <td data-l="{{ __('media.lg.col.req') }}">
                            @if ($a->subject)
                                <a href="{{ route('media-requests.show', $a->subject) }}" wire:navigate style="display:grid;gap:2px;text-decoration:none">
                                    <span class="sub num ltr">{{ $a->subject->reference }}</span>
                                    <b class="ltr" style="overflow-wrap:anywhere">{{ $a->subject->displayName() }}</b>
                                </a>
                            @endif
                        </td>
                        <td data-l="{{ __('media.lg.col.details') }}" class="sub" style="overflow-wrap:anywhere">{{ \App\Livewire\MediaRequests\Log::details($a, $catalog) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;padding:32px" class="sub">{{ __('media.lg.none') }}</td></tr>
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
