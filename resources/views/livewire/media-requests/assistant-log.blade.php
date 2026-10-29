@php
    $locale = app()->getLocale();
    $catalog = app(\App\Support\MediaRequests\MediaCatalog::class);
    $guess = function (?string $service, ?string $type) use ($catalog) {
        return $service ? __('media.svc.'.$catalog->serviceKey($service)).' · '.__('media.type.'.$type) : '—';
    };
    $place = fn ($m) => $m ? ($locale === 'ar' ? $m->name_ar : $m->name_en) : null;
    $tiles = ['missed' => ['al.missed', 'alert'], 'corrected' => ['al.corrected', 'edit'], 'all' => ['al.all', 'layers'], 'lessons' => ['al.lessons', 'spark']];
@endphp

<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.al.title') }}</h1>
            <p class="lead">{{ __($canTeach ? 'media.al.leadTeach' : 'media.al.lead') }}</p>
        </div>
    </div>

    <div class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))" role="group" aria-label="{{ __('media.al.title') }}">
        @foreach ($tiles as $key => [$label, $icon])
            <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    @class(['stat', 'hot' => in_array($key, ['missed', 'corrected'], true) && $counts[$key] > 0])>
                <x-ic :n="$icon" />
                <span class="sl">{{ __('media.'.$label) }}</span>
                <span class="sn num">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    @if ($filter === 'lessons')
        {{-- Everything taught so far: the word, and what it means. --}}
        @if ($lessons->isEmpty())
            <div class="empty"><x-ic n="spark" /><b>{{ __('media.al.noLessons') }}</b></div>
        @else
            <div class="tablewrap">
                <table class="tbl">
                    <thead><tr>
                        <th scope="col">{{ __('media.al.t.phrase') }}</th>
                        <th scope="col">{{ __('media.al.t.means') }}</th>
                        <th scope="col">{{ __('media.al.t.by') }}</th>
                        <th scope="col"><span class="vh">{{ __('media.people.actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($lessons as $l)
                            <tr wire:key="ls-{{ $l->id }}">
                                <td data-l="{{ __('media.al.t.phrase') }}"><b dir="auto">«{{ $l->phrase }}»</b></td>
                                <td data-l="{{ __('media.al.t.means') }}">
                                    <span class="meta" style="font-size:14px">
                                        @if ($l->service)<span>{{ $guess($l->service, $l->type) }}@if ($l->format) · {{ __('media.o.format.'.$l->format) }}@endif</span>@endif
                                        @if ($l->program)<span class="ltr">{{ trim($l->program->code.' '.$l->program->slug) }}</span>@endif
                                        @if ($l->city)<span>{{ $place($l->city) }}</span>@endif
                                    </span>
                                </td>
                                <td data-l="{{ __('media.al.t.by') }}">{{ $l->teacher?->nameForLocale($locale) ?? '—' }} <small class="sub num">{{ $l->created_at->format('d-m-Y') }}</small></td>
                                <td style="text-align:end">
                                    @if ($canTeach)
                                        <button type="button" class="btn-q" x-on:click="mrConfirm(@js(__('media.al.t.forget', ['p' => $l->phrase]))).then(ok => ok && $wire.deleteLesson({{ $l->id }}))"><x-ic n="x" />{{ __('media.al.t.delete') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @elseif ($rows->isEmpty())
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
                        @if ($canTeach)<th scope="col"><span class="vh">{{ __('media.al.t.teach') }}</span></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="al-{{ $row->id }}">
                            <td data-l="{{ __('media.al.col.text') }}" style="min-width:240px">
                                <b style="display:block;white-space:pre-wrap;overflow-wrap:anywhere" dir="auto">{{ $row->text }}</b>
                                <small class="sub num">{{ $row->created_at->format('d-m-Y H:i') }}</small>
                            </td>
                            <td data-l="{{ __('media.al.col.understood') }}">
                                @if ($row->understood)
                                    <span style="display:block">{{ $guess($row->service, $row->type) }}</span>
                                    <span class="meta" style="font-size:13px">
                                        @if ($row->program)<span class="ltr">{{ trim($row->program->code.' '.$row->program->slug) }}</span>@endif
                                        @if ($row->city)<span>{{ $place($row->city) }}</span>@endif
                                        @if ($row->needed_by)<span class="num">{{ $row->needed_by->format('d-m-Y') }}</span>@endif
                                    </span>
                                @else
                                    <span class="chip attn"><x-ic n="alert" />{{ __('media.al.notUnderstood') }}</span>
                                @endif
                                {{-- Taught since: how it reads this line now. --}}
                                @if ($row->lesson)
                                    @php($n = $now($row->text))
                                    <span class="chip ok" style="margin-top:6px"><x-ic n="spark" />{{ __('media.al.t.now') }} {{ $guess($n['service'], $n['type']) }}</span>
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
                            @if ($canTeach)
                                <td style="text-align:end;white-space:nowrap">
                                    @if ($teaching !== $row->id)
                                        <button type="button" class="btn btn-o btn-sm" wire:click="teach({{ $row->id }})"><x-ic n="spark" />{{ __($row->lesson ? 'media.al.t.again' : 'media.al.t.teach') }}</button>
                                    @endif
                                </td>
                            @endif
                        </tr>

                        {{-- The lesson: the word people used, and what it means. --}}
                        @if ($teaching === $row->id)
                            <tr class="teachrow" wire:key="tf-{{ $row->id }}">
                                <td colspan="5">
                                    <form wire:submit="saveLesson" class="teachform">
                                        <div class="field full">
                                            <label for="t-phrase">{{ __('media.al.t.phrase') }}</label>
                                            <input id="t-phrase" class="inp" dir="auto" maxlength="120" wire:model="phrase" autofocus>
                                            <p class="hint">{{ __('media.al.t.phraseHint') }}</p>
                                            @error('phrase')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                        </div>
                                        <div class="field">
                                            <label for="t-kind">{{ __('media.al.t.kind') }}</label>
                                            <select id="t-kind" class="inp" wire:model.live="kind">
                                                <option value="">—</option>
                                                @foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                            </select>
                                            @error('kind')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                        </div>
                                        @if ($kind === '001.new')
                                            <div class="field">
                                                <label for="t-format">{{ __('media.al.t.format') }}</label>
                                                <select id="t-format" class="inp" wire:model="format">
                                                    <option value="">—</option>
                                                    @foreach ($formats as $f)<option value="{{ $f }}">{{ __('media.o.format.'.$f) }}</option>@endforeach
                                                </select>
                                            </div>
                                        @endif
                                        <div class="field">
                                            <label for="t-program">{{ __('media.f.program') }}</label>
                                            <select id="t-program" class="inp ltr" wire:model="program_id">
                                                <option value="">—</option>
                                                @foreach ($programs as $p)<option value="{{ $p->id }}">{{ trim($p->code.' '.$p->slug) }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="t-city">{{ __('media.f.city') }}</label>
                                            <select id="t-city" class="inp" wire:model="city_id">
                                                <option value="">—</option>
                                                @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $place($c) }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="btnline full">
                                            <button type="submit" class="btn btn-p btn-sm" wire:loading.attr="data-loading"><span class="spin"></span><x-ic n="check" />{{ __('media.al.t.save') }}</button>
                                            <button type="button" class="btn btn-g btn-sm" wire:click="cancelTeach">{{ __('media.wz.cancel') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
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
