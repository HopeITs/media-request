@php
    $locale = app()->getLocale();
    $catalog = app(\App\Support\MediaRequests\MediaCatalog::class);
    $guess = function (?string $service, ?string $type) use ($catalog) {
        return $service ? __('media.svc.'.$catalog->serviceKey($service)).' · '.__('media.type.'.$type) : '—';
    };
    $tiles = ['missed' => ['al.missed', 'alert'], 'corrected' => ['al.corrected', 'edit'], 'all' => ['al.all', 'layers']];
@endphp

<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.al.title') }}</h1>
            <p class="lead">{{ __('media.al.lead') }}</p>
        </div>
    </div>

    <div class="stats" style="grid-template-columns:repeat(3,minmax(0,1fr))" role="group" aria-label="{{ __('media.al.title') }}">
        @foreach ($tiles as $key => [$label, $icon])
            <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    @class(['stat', 'hot' => $key !== 'all' && $counts[$key] > 0])>
                <x-ic :n="$icon" />
                <span class="sl">{{ __('media.'.$label) }}</span>
                <span class="sn num">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <div class="empty"><x-ic n="spark" /><b>{{ __('media.al.none') }}</b></div>
    @else
        <div class="tablewrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th scope="col">{{ __('media.al.col.text') }}</th>
                        <th scope="col">{{ __('media.al.col.understood') }}</th>
                        <th scope="col">{{ __('media.al.col.final') }}</th>
                        <th scope="col">{{ __('media.lg.col.who') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="al-{{ $row->id }}">
                            <td data-l="{{ __('media.al.col.text') }}" style="min-width:240px">
                                <b style="display:block;white-space:pre-wrap;overflow-wrap:anywhere">{{ $row->text }}</b>
                                <small class="sub num">{{ $row->created_at->format('d-m-Y H:i') }}</small>
                            </td>
                            <td data-l="{{ __('media.al.col.understood') }}">
                                @if ($row->understood)
                                    <span style="display:block">{{ $guess($row->service, $row->type) }}</span>
                                    <span class="meta" style="font-size:13px">
                                        @if ($row->program)<span class="ltr">{{ trim($row->program->code.' '.$row->program->slug) }}</span>@endif
                                        @if ($row->city)<span>{{ $locale === 'ar' ? $row->city->name_ar : $row->city->name_en }}</span>@endif
                                        @if ($row->needed_by)<span class="num">{{ $row->needed_by->format('d-m-Y') }}</span>@endif
                                    </span>
                                @else
                                    <span class="chip attn"><x-ic n="alert" />{{ __('media.al.notUnderstood') }}</span>
                                @endif
                            </td>
                            <td data-l="{{ __('media.al.col.final') }}">
                                @if ($row->mediaRequest)
                                    <a href="{{ route('media-requests.show', $row->mediaRequest) }}" wire:navigate style="display:grid;gap:2px;text-decoration:none">
                                        <span>{{ $guess($row->final_service, $row->final_type) }}</span>
                                        <span class="sub num ltr">{{ $row->mediaRequest->reference }}</span>
                                    </a>
                                    @if ($row->corrected)<span class="tag rush" style="margin-top:4px"><x-ic n="edit" />{{ __('media.al.changed') }}</span>@endif
                                @else
                                    <span class="sub">{{ __('media.al.notSent') }}</span>
                                @endif
                            </td>
                            <td data-l="{{ __('media.lg.col.who') }}">{{ $row->employee?->nameForLocale($locale) ?? '—' }}</td>
                        </tr>
                    @endforeach
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
    @endif
</div>
