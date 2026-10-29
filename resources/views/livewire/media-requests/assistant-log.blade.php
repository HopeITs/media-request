@php
    $locale = app()->getLocale();
    $catalog = app(\App\Support\MediaRequests\MediaCatalog::class);
    $guess = function (?string $service, ?string $type) use ($catalog) {
        return $service ? __('media.svc.'.$catalog->serviceKey($service)).' · '.__('media.type.'.$type) : '—';
    };
    $place = fn ($m) => $m ? ($locale === 'ar' ? $m->name_ar : $m->name_en) : null;
    // What a lesson means, in one line: the request, the HC Generator tool, the HC_PACK search or the reply; then program and place.
    $means = function (\App\Models\MediaAssistantLesson $l) use ($guess, $place) {
        return implode(' · ', array_filter([
            match ($l->meaning) {
                'generator' => 'HC Generator: '.__('media.gen.tool.'.$l->tool),
                'pack' => 'HC_PACK: «'.$l->pack_query.'»',
                'reply' => __('media.al.t.replyShort').': '.$l->reply,
                default => $l->service ? $guess($l->service, $l->type).($l->format ? ' · '.__('media.o.format.'.$l->format) : '') : null,
            },
            $l->program ? trim($l->program->code.' '.$l->program->slug) : null,
            $place($l->city),
        ]));
    };
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
                                <td data-l="{{ __('media.al.t.phrase') }}"><b dir="auto">«{{ $l->phrase }}»</b>@if ($l->keywords)<span class="skills" style="margin-top:4px">@foreach ($l->keywords as $w)<span class="chip soft" dir="auto">{{ $w }}</span>@endforeach</span>@endif</td>
                                <td data-l="{{ __('media.al.t.means') }}" dir="auto">{{ $means($l) }}</td>
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
                                    <span class="chip ok" style="margin-top:6px;white-space:normal" dir="auto"><x-ic n="spark" />{{ __('media.al.t.now') }} {{ $means($row->lesson) }}</span>
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
                                        <div class="field full">
                                            <label for="t-words">{{ __('media.al.t.keywords') }}</label>
                                            <textarea id="t-words" class="inp" rows="2" dir="auto" wire:model="keywords" placeholder="{{ __('media.al.t.keywordsPh') }}"></textarea>
                                            <p class="hint">{{ __('media.al.t.keywordsHint') }}</p>
                                        </div>
                                        {{-- One list: a kind of request, a ready file, an HC Generator tool, or something else. --}}
                                        <div class="field">
                                            <label for="t-kind">{{ __('media.al.t.means') }}</label>
                                            <select id="t-kind" class="inp" wire:model.live="choice">
                                                <option value="">—</option>
                                                <optgroup label="{{ __('media.al.m.request') }}">
                                                    @foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                                </optgroup>
                                                <optgroup label="HC_PACK">
                                                    <option value="pack">{{ __('media.al.m.pack') }}</option>
                                                </optgroup>
                                                <optgroup label="HC Generator">
                                                    @foreach ($tools as $tl)<option value="generator:{{ $tl }}">{{ __('media.gen.tool.'.$tl) }}</option>@endforeach
                                                </optgroup>
                                                <optgroup label="{{ __('media.al.m.other') }}">
                                                    <option value="reply">{{ __('media.al.m.reply') }}</option>
                                                </optgroup>
                                            </select>
                                            @error('kind')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                            @error('tool')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                        </div>
                                        @if ($choice === '001.new')
                                            <div class="field">
                                                <label for="t-format">{{ __('media.al.t.format') }}</label>
                                                <select id="t-format" class="inp" wire:model="format">
                                                    <option value="">—</option>
                                                    @foreach ($formats as $fm)<option value="{{ $fm }}">{{ __('media.o.format.'.$fm) }}</option>@endforeach
                                                </select>
                                            </div>
                                        @elseif ($choice === 'pack')
                                            <div class="field">
                                                <label for="t-pack">{{ __('media.al.t.pack') }}</label>
                                                <input id="t-pack" class="inp" dir="auto" maxlength="120" wire:model="pack_query" placeholder="{{ __('media.hp.ph') }}">
                                                @error('pack_query')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                                            </div>
                                        @elseif ($choice === 'reply')
                                            <div class="field full">
                                                <label for="t-reply">{{ __('media.al.t.reply') }}</label>
                                                <textarea id="t-reply" class="inp" rows="2" dir="auto" maxlength="500" wire:model="reply" placeholder="{{ __('media.al.t.replyPh') }}"></textarea>
                                                @error('reply')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
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
