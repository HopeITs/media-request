@php($me = auth()->user()->employee_id)

<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.h.board') }}</h1>
            <p class="lead">{{ __('media.h.board.l') }}</p>
        </div>
        <div class="filters" style="margin:0">
            <label class="check"><input type="checkbox" wire:model.live="onlyMine">{{ __('media.nav.assign') }}</label>
            <label for="svc" class="vh">{{ __('media.q.svc') }}</label>
            <label for="field" class="vh">{{ __('media.spec.title') }}</label>
            <select id="field" class="inp" style="width:auto;min-width:170px" wire:model.live="field">
                <option value="">{{ __('media.spec.all') }}</option>
                @foreach (config('media-requests.specialties') as $key => $s)<option value="{{ $key }}">{{ __('media.spec.'.$key) }}</option>@endforeach
            </select>
            <select id="svc" class="inp" style="width:auto;min-width:170px" wire:model.live="service">
                <option value="">{{ __('media.q.svc') }}</option>
                @foreach (config('media-requests.services') as $code => $svc)
                    <option value="{{ $code }}">{{ __('media.svc.'.$svc['key']) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="board">
        @foreach ($columns as $column => $cards)
            <section @class(['bcol', 'is-empty' => $cards->isEmpty()]) aria-labelledby="col-{{ $column }}">
                <h2 id="col-{{ $column }}"><span>{{ __('media.col.'.$column) }}</span><span class="n num">{{ $cards->count() }}</span></h2>
                @forelse ($cards as $r)
                    @php($mine = Gate::allows('isOwner', $r))
                    @php($spec = $r->specialty())
                    @php($yours = in_array($spec, $skills, true) && $r->isOpen())
                    {{-- Read top to bottom: flags, name, status, who has it, when it is needed, reference. --}}
                    <a href="{{ route('media-requests.show', $r) }}" wire:navigate @class(['kc', 'mine' => $mine])>
                        @if ($mine || $yours || $r->priority === 'high' || $r->isLate())
                            <div class="row">
                                @if ($mine)<span class="tag act"><x-ic n="arrow" class="flip" />{{ __('media.tag.act') }}</span>@endif
                                @if ($yours && ! $mine)<span class="tag soft"><x-ic n="user" />{{ __('media.spec.yours') }}</span>@endif
                                @if ($r->priority === 'high')<span class="tag rush">{{ __('media.prio.high') }}</span>@endif
                                @if ($r->isLate())<span class="tag late"><x-ic n="flame" />{{ __('media.tag.late') }}</span>@endif
                            </div>
                        @endif
                        <x-media.name :r="$r" class="nm" />
                        <div class="row"><x-media.status :r="$r" :late="false" />@if ($r->wantsPrint())<span class="tag soft"><x-ic n="printer" />{{ __('media.tag.withPrint') }}</span>@endif @if ($r->needsQuote() && $r->isOpen())<span class="tag rush">{{ __('media.tag.quote') }}</span>@endif @if ($spec)<span class="tag soft spec"><x-ic :n="config('media-requests.specialties.'.$spec.'.icon')" />{{ __('media.spec.'.$spec) }}</span>@endif</div>
                        <div class="kfoot">
                            @if ($r->isOpen() && $r->owner())
                                <span><x-ic n="user" /><b class="owner">{{ $r->ownerName() }}</b>@if ($r->status_changed_at)<span class="sub">· {{ $r->status_changed_at->diffForHumans(null, true) }}</span>@endif</span>
                            @endif
                            <span class="kref">
                                @if ($r->needed_by)<span><x-ic n="cal" /><span class="num">{{ $r->needed_by->format('d-m-Y') }}</span></span>@endif
                                <span class="id num ltr">{{ $r->reference }}</span>
                            </span>
                        </div>
                    </a>
                @empty
                    <p class="colempty">{{ __('media.col.none') }}</p>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
