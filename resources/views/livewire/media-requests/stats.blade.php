@php
    $locale = app()->getLocale();
    $catalog = app(\App\Support\MediaRequests\MediaCatalog::class);
    $periods = \App\Livewire\MediaRequests\Stats::PERIODS;
    $pct = fn (int $n, int $of) => $of > 0 ? (int) round($n * 100 / $of) : 0;
    $delivered = $s['requests']['delivered'];
    $timingRows = [
        ['early', 'arrow', $s['timing']['early']],
        ['onTime', 'check', $s['timing']['onTime']],
        ['late', 'alert', $s['timing']['late']],
    ];
    $serviceLabel = fn (string $code) => __('media.svc.'.$catalog->serviceKey($code));
    $typeLabel = function (string $key) use ($serviceLabel) {
        [$svc, $type] = explode('.', $key) + [1 => ''];

        return $serviceLabel($svc).' · '.__('media.type.'.$type);
    };
@endphp

{{--
    Statistics. Numbers first (tiles), then one horizontal bar list per question, each bar
    labelled with its value so nothing depends on colour; the hover title gives the share.
--}}
<div class="statspage">
    <div class="ph">
        <div>
            <h1>{{ __('media.nav.stats') }}</h1>
            <p class="lead">{{ __('media.stats.lead') }}</p>
        </div>
        <div class="seg" role="tablist" aria-label="{{ __('media.stats.period') }}">
            @foreach ($periods as $p)
                <button type="button" role="tab" wire:click="$set('days', {{ $p }})" @class(['on' => $days === $p]) :aria-selected="@js($days === $p)">
                    {{ $p ? trans_choice('media.stats.lastDays', $p, ['n' => $p]) : __('media.stats.allTime') }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- The team, now --}}
    <h2 class="sth2">{{ __('media.stats.teamNow') }}</h2>
    <div class="tiles">
        <div class="tile-n"><span class="v num">{{ $s['team']['members'] }}</span><span class="l">{{ __('media.stats.members') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $s['team']['working'] }}</span><span class="l">{{ __('media.stats.working') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $s['team']['members'] - $s['team']['holding'] }}</span><span class="l">{{ __('media.stats.free') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $s['requests']['open'] }}</span><span class="l">{{ __('media.stats.open') }}</span>
            <small class="sub">{{ __('media.stats.openParts', ['claim' => $s['requests']['toClaim'], 'approval' => $s['requests']['waitingApproval']]) }}</small></div>
    </div>

    {{-- The period --}}
    <h2 class="sth2">{{ $days ? trans_choice('media.stats.inLastDays', $days, ['n' => $days]) : __('media.stats.allTime') }}</h2>
    <div class="tiles">
        <div class="tile-n"><span class="v num">{{ $s['requests']['created'] }}</span><span class="l">{{ __('media.stats.created') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $delivered }}</span><span class="l">{{ __('media.stats.delivered') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $delivered ? $pct($s['timing']['early'] + $s['timing']['onTime'], $delivered).'%' : '—' }}</span><span class="l">{{ __('media.stats.onTimeRate') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $s['avgDays'] ?? '—' }}</span><span class="l">{{ __('media.stats.avgDays') }}</span></div>
        <div class="tile-n"><span class="v num">{{ $s['returned'] }}</span><span class="l">{{ __('media.stats.returned') }}</span></div>
    </div>

    <div class="statgrid">
        {{-- Delivery timing --}}
        <section class="card statcard" aria-labelledby="st-time">
            <h3 id="st-time">{{ __('media.stats.timing') }}</h3>
            @if ($delivered === 0)
                <p class="sub">{{ __('media.stats.noDeliveries') }}</p>
            @else
                <ul class="hbars">
                    @foreach ($timingRows as [$key, $icon, $n])
                        <li title="{{ $n }} · {{ $pct($n, $delivered) }}%">
                            <span class="k"><x-ic :n="$icon" />{{ __('media.stats.t.'.$key) }}</span>
                            <span class="bar"><i style="width:{{ $pct($n, $delivered) }}%"></i></span>
                            <b class="num">{{ $n }} <small>{{ $pct($n, $delivered) }}%</small></b>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- By field --}}
        <section class="card statcard" aria-labelledby="st-spec">
            <h3 id="st-spec">{{ __('media.stats.bySpecialty') }}</h3>
            @php($max = max(1, collect($s['bySpecialty'])->max('n') ?? 1))
            @forelse ($s['bySpecialty'] as $row)
                @if ($loop->first)<ul class="hbars">@endif
                <li title="{{ $row['n'] }} · {{ $pct($row['n'], $s['requests']['created']) }}%">
                    <span class="k">@if ($row['key'] !== 'other')<x-ic :n="config('media-requests.specialties.'.$row['key'].'.icon')" />{{ __('media.spec.'.$row['key']) }}@else{{ __('media.stats.otherField') }}@endif</span>
                    <span class="bar"><i style="width:{{ $pct($row['n'], $max) }}%"></i></span>
                    <b class="num">{{ $row['n'] }}</b>
                </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="sub">{{ __('media.stats.none') }}</p>
            @endforelse
        </section>

        {{-- By type --}}
        <section class="card statcard wide" aria-labelledby="st-type">
            <h3 id="st-type">{{ __('media.stats.byType') }}</h3>
            @php($max = max(1, collect($s['byType'])->max('n') ?? 1))
            @forelse ($s['byType'] as $row)
                @if ($loop->first)<ul class="hbars">@endif
                <li title="{{ $row['n'] }} · {{ $pct($row['n'], $s['requests']['created']) }}%">
                    <span class="k">{{ $typeLabel($row['key']) }}</span>
                    <span class="bar"><i style="width:{{ $pct($row['n'], $max) }}%"></i></span>
                    <b class="num">{{ $row['n'] }}</b>
                </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="sub">{{ __('media.stats.none') }}</p>
            @endforelse
        </section>

        {{-- People --}}
        <section class="card statcard wide" aria-labelledby="st-people">
            <h3 id="st-people">{{ __('media.stats.people') }}</h3>
            <div class="tablewrap">
                <table class="stable">
                    <thead><tr>
                        <th scope="col">{{ __('media.stats.col.person') }}</th>
                        <th scope="col" class="n">{{ __('media.stats.col.delivered') }}</th>
                        <th scope="col" class="n">{{ __('media.stats.col.onTime') }}</th>
                        <th scope="col" class="n">{{ __('media.stats.col.returned') }}</th>
                        <th scope="col" class="n">{{ __('media.stats.col.working') }}</th>
                        <th scope="col" class="n">{{ __('media.stats.col.holding') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($s['people'] as $p)
                            <tr>
                                <th scope="row">{{ $p['employee']->nameForLocale($locale) }}
                                    @foreach ($p['skills'] as $sk)<x-ic :n="config('media-requests.specialties.'.$sk.'.icon')" class="skill" title="{{ __('media.spec.'.$sk) }}" />@endforeach</th>
                                <td class="n num">{{ $p['delivered'] }}</td>
                                <td class="n num">{{ $p['delivered'] ? $pct($p['onTime'], $p['delivered']).'%' : '—' }}</td>
                                <td class="n num">{{ $p['returned'] }}</td>
                                <td class="n num">{{ $p['working'] }}</td>
                                <td class="n num">{{ $p['holding'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint">{{ __('media.stats.peopleHint') }}</p>
        </section>
    </div>
</div>
