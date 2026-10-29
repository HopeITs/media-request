@php
    $steps = [1 => ['wz.st1', 'layers'], 2 => ['wz.st2', 'list'], 3 => ['wz.st3', 'user'], 4 => ['wz.st4', 'clip']];
    $locale = app()->getLocale();
    $other = $locale === 'ar' ? 'en' : 'ar';
    $svcIcon = ['001' => 'pen', '002' => 'camera', '003' => 'video'];
    $typeIcon = ['new' => 'pen', 'edit' => 'edit', 'print' => 'printer', 'production' => 'box', 'shoot' => 'camera', 'export' => 'download'];
    $err = fn (string $k) => $errors->has($k) ? 'aria-invalid=true' : '';
@endphp

<div>
    <div class="mback" x-data="{ show: @entangle('show') }" x-show="show" x-cloak
         x-on:keydown.escape.window="if (show) $wire.close()"
         x-transition:enter="transition-opacity" x-transition.opacity.duration.150ms
         role="dialog" aria-modal="true" aria-labelledby="wz-title">
        <form class="modal" wire:submit="{{ $step === 4 ? 'submit' : 'next' }}" x-on:click.outside="$wire.close()">
            <div class="mh">
                <h2 id="wz-title"><x-media.bi :k="$editingId ? 'media.wz.edit' : 'media.wz.title'" /></h2>
                <button type="button" class="iconbtn" wire:click="close" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
            </div>

            <ol class="msteps">
                @foreach ($steps as $n => [$label, $icon])
                    @continue($editingId && $n === 1)
                    <li data-s="{{ $n < $step ? 'done' : ($n === $step ? 'cur' : 'todo') }}">
                        <button type="button" wire:click="goTo({{ $n }})" @disabled($n >= $step) @if ($n === $step) aria-current="step" @endif>
                            <span class="n">@if ($n < $step)<x-ic n="check" />@else{{ $n }}@endif</span>
                            <span class="l">{{ __('media.'.$label) }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <div class="mb">
                {{-- 1. What do you need? --}}
                @if ($step === 1)
                    <div class="aibox">
                        <div class="ai-h"><x-ic n="spark" /><x-media.bi k="media.ai.title" /></div>
                        <div class="ai-row">
                            <label for="mr-prompt" class="vh">{{ __('media.ai.title') }}</label>
                            <textarea id="mr-prompt" class="inp" rows="2" wire:model="prompt" placeholder="{{ __('media.ai.ph') }}" x-data="mrTypewriter(@js(__('media.ai.examples')))"
                                      x-on:keydown.enter.prevent.exact="$wire.analyze()"></textarea>
                            <button type="button" class="btn btn-o" wire:click="analyze" wire:loading.attr="data-loading" wire:target="analyze">
                                <span class="spin"></span><x-ic n="spark" wire:loading.remove wire:target="analyze" />{{ __('media.ai.go') }}
                            </button>
                        </div>

                        @if ($assistantMissed)
                            <p class="hint">{{ __('media.ai.unknown') }}</p>
                        @endif

                        @if ($assistantUsed && $service)
                            <div class="msg bot" aria-live="polite">
                                <h3><x-ic n="spark" />{{ __('media.ai.understood') }}</h3>
                                <div class="aitags">
                                    <span class="tag"><x-ic :n="$svcIcon[$service] ?? 'file'" />{{ __('media.svc.'.$catalog->serviceKey($service)) }} · {{ __('media.type.'.$type) }}</span>
                                    @foreach (['format', 'printItem', 'kind'] as $k)
                                        @if (! empty($answers[$k]) && is_string($answers[$k]))<span class="tag">{{ $catalog->optionLabel($k, $answers[$k]) }}</span>@endif
                                    @endforeach
                                    @if ($program = $programs->firstWhere('id', $program_id))<span class="tag ltr">{{ trim($program->code.' '.$program->slug) }}</span>@endif
                                    @if ($city = $cities->firstWhere('id', $city_id))<span class="tag">{{ $locale === 'ar' ? $city->name_ar : $city->name_en }}</span>@endif
                                    @if ($needed_by)<span class="tag"><x-ic n="cal" /><span class="num">{{ \Illuminate\Support\Carbon::parse($needed_by)->format('d-m-Y') }}</span></span>@endif
                                </div>

                                @if ($questions !== [])
                                    <p class="sub">{{ __('media.ai.qhead') }}</p>
                                    <div class="fgrid">
                                        @foreach ($questions as $q)
                                            <div class="field" wire:key="q-{{ $q['id'] }}">
                                                <label for="q-{{ $q['id'] }}"><span class="bi bi-v"><span>{{ $q[$locale === 'ar' ? 'ar' : 'en'] }}</span><span class="bi2" lang="{{ $other }}">{{ $q[$other] }}</span></span></label>
                                                @if ($q['kind'] === 'program')
                                                    <select id="q-{{ $q['id'] }}" class="inp ltr" wire:model="questionAnswers.{{ $q['id'] }}">
                                                        <option value="">{{ __('media.o.select') }}</option>
                                                        @foreach ($programs as $p)<option value="{{ $p->id }}">{{ trim($p->code.' '.$p->slug) }}</option>@endforeach
                                                    </select>
                                                @elseif ($q['kind'] === 'city')
                                                    <select id="q-{{ $q['id'] }}" class="inp" wire:model="questionAnswers.{{ $q['id'] }}">
                                                        <option value="">{{ __('media.o.select') }}</option>
                                                        @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $locale === 'ar' ? $c->name_ar : $c->name_en }}</option>@endforeach
                                                    </select>
                                                @elseif ($q['kind'] === 'textarea')
                                                    <textarea id="q-{{ $q['id'] }}" class="inp" rows="2" wire:model="questionAnswers.{{ $q['id'] }}"></textarea>
                                                @else
                                                    <input id="q-{{ $q['id'] }}" class="inp" type="{{ $q['kind'] === 'date' ? 'date' : 'text' }}" wire:model="questionAnswers.{{ $q['id'] }}">
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="sub">{{ __('media.ai.filled') }}</p>
                            </div>
                        @endif

                        @if ($reply)
                            {{-- Taught by the media manager: something the media team does not do, and where it goes. --}}
                            <div class="banner soft" aria-live="polite" wire:key="reply-{{ $reply->id }}"><x-ic n="msg" /><span dir="auto">{{ $reply->reply }}</span></div>
                        @endif
                        @if ($generator)
                            {{-- Something HC Generator makes: offer the tool first; the request stays possible. --}}
                            <div class="genhit" aria-live="polite" wire:key="gen-{{ $generator['key'] }}">
                                <span class="appicon"><x-ic :n="$generator['icon']" /></span>
                                <div>
                                    <b>{{ __('media.gen.head') }}</b>
                                    <p><span class="ltr">{{ __('media.gen.tool.'.$generator['key']) }}</span>: {{ __('media.gen.hint.'.$generator['key']) }}</p>
                                    <a href="{{ $generator['url'] }}" target="_blank" rel="noopener" class="btn btn-p">
                                        <span lang="en">{{ __('media.gen.open', ['tool' => __('media.gen.tool.'.$generator['key'])]) }}</span><x-ic n="external" />
                                    </a>
                                    <small>{{ __('media.gen.still') }}</small>
                                </div>
                            </div>
                        @endif

                        @if ($suggestions->isNotEmpty())
                            <div class="packhit">
                                <b class="sub" style="color:var(--text)"><x-ic n="box" style="vertical-align:-3px" /> {{ __('media.ai.pack') }}</b>
                                <ul>
                                    @foreach ($suggestions as $file)
                                        <li><x-ic n="file" /><span class="ltr">{{ $file['name'] }}</span></li>
                                    @endforeach
                                </ul>
                                <a href="{{ route('media-requests.pack', ['q' => $prompt]) }}" class="btn-q" style="padding:0;justify-self:start">{{ __('media.ai.packAll') }}<x-ic n="arrow" class="flip" /></a>
                            </div>
                        @endif
                    </div>

                    <div class="ai-or">{{ __('media.ai.orPick') }}</div>

                    {{-- Pick the service first; only its types appear below. Switching tiles is instant (no server round trip). --}}
                    <div x-data="{ svc: @js($service) }" wire:key="svc-pick-{{ $service ?? 'none' }}">
                        <div class="svcs" role="radiogroup" aria-label="{{ __('media.wz.q1') }}">
                            @foreach ($catalog->requestServices() as $svc)
                                @php($svcKey = $catalog->serviceKey($svc))
                                <button type="button" class="svc" role="radio" x-on:click="svc = '{{ $svc }}'" :aria-checked="svc === '{{ $svc }}'">
                                    <span class="oi"><x-ic :n="$svcIcon[$svc] ?? 'file'" /></span>
                                    <x-media.bi :k="'media.svc.'.$svcKey" col />
                                </button>
                            @endforeach
                        </div>

                        @foreach ($catalog->requestServices() as $svc)
                            @php($svcKey = $catalog->serviceKey($svc))
                            <div x-show="svc === '{{ $svc }}'" x-cloak class="types">
                                <p class="sub">{{ __('media.sd.'.$svcKey) }}</p>
                                <div class="typegrid">
                                    @foreach ($catalog->types($svc) as $t)
                                        <button type="button" class="typebtn" wire:click="chooseType('{{ $svc }}', '{{ $t }}')"
                                                aria-pressed="{{ $service === $svc && $type === $t ? 'true' : 'false' }}">
                                            <span class="oi"><x-ic :n="$typeIcon[$t] ?? 'file'" /></span>
                                            <span><b>{{ __('media.type.'.$t) }}</b><small>{{ __('media.td.'.$svcKey.'.'.$t) }}</small></span>
                                            <x-ic n="check" class="ck" />
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @error('service')<p class="err" role="alert" style="margin-top:12px"><x-ic n="alert" />{{ $message }}</p>@enderror
                @endif

                {{-- 2. Details --}}
                @if ($step === 2)
                    <h3 class="sth"><x-ic :n="$typeIcon[$type] ?? 'list'" /><x-media.bi :k="'media.type.'.$type" /></h3>
                    @if ($type === 'print')
                        <div class="banner soft" style="margin-bottom:18px"><x-ic n="alert" /><span>{{ __('media.wz.printnote') }}</span></div>
                    @endif
                    <div class="fgrid">
                        @foreach ($details as $field)
                            @include('livewire.media-requests.partials.field', ['field' => $field])
                        @endforeach
                    </div>
                @endif

                {{-- 3. Who and when --}}
                @if ($step === 3)
                    <h3 class="sth"><x-ic n="user" /><x-media.bi k="media.wz.s3t" /></h3>
                    <div class="fgrid">
                        {{-- Media team: a request asked outside the app is entered here, for the person who asked. --}}
                        @if ($forPeople->isNotEmpty())
                            <div class="field full">
                                <label for="mr-for"><x-media.bi k="media.f.for" /></label>
                                <select id="mr-for" class="inp" wire:model.live="forEmployeeId">
                                    <option value="">{{ __('media.f.forMe') }}</option>
                                    @foreach ($forPeople as $p)<option value="{{ $p->id }}">{{ $p->nameForLocale($locale) }}</option>@endforeach
                                </select>
                                <p class="hint">{{ __('media.h.for') }}</p>
                                @error('for')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div class="field">
                            <label for="mr-program"><x-media.bi k="media.f.program" /><span class="req" aria-hidden="true">*</span></label>
                            <select id="mr-program" class="inp ltr" wire:model.live="program_id" {{ $err('program_id') }}>
                                <option value="">{{ __('media.o.select') }}</option>
                                @foreach ($programs as $p)<option value="{{ $p->id }}">{{ trim($p->code.' '.$p->slug) }}</option>@endforeach
                            </select>
                            @error('program_id')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mr-city"><x-media.bi k="media.f.city" /><span class="req" aria-hidden="true">*</span></label>
                            <select id="mr-city" class="inp" wire:model.live="city_id" {{ $err('city_id') }}>
                                <option value="">{{ __('media.o.select') }}</option>
                                @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $locale === 'ar' ? $c->name_ar : $c->name_en }}</option>@endforeach
                            </select>
                            @error('city_id')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                        <div class="field full">
                            <label for="mr-title"><x-media.bi k="media.f.name" /><span class="req" aria-hidden="true">*</span></label>
                            <p class="hint" id="mr-title-h">{{ __('media.h.name') }}</p>
                            <div class="namebox">
                                <span class="pre">{{ $prefix }}_</span>
                                <input id="mr-title" class="inp" type="text" maxlength="80" wire:model.blur="title" placeholder="{{ __('media.ph.name') }}" {{ $err('title') }}
                                       lang="en" dir="ltr" autocomplete="off" spellcheck="false" aria-describedby="mr-title-h"
                                       x-on:input="$refs.titleWarn.hidden = ! /[^A-Za-z0-9 _-]/.test($el.value)">
                            </div>
                            <p class="err" x-ref="titleWarn" hidden aria-live="polite"><x-ic n="alert" />{{ __('media.err.nameEnglish') }}</p>
                            @error('title')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="mr-date"><x-media.bi k="media.f.deadline" /><span class="req" aria-hidden="true">*</span></label>
                            <input id="mr-date" class="inp" type="date" min="{{ today()->toDateString() }}" wire:model.live="needed_by" {{ $err('needed_by') }}>
                            @error('needed_by')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                            {{-- Working time: the earliest the team can do it, and the days that do not count. --}}
                            @if ($earliest)
                                <p class="hint workhint"><x-ic n="clock" />{{ __('media.work.earliest', ['date' => $earliest->translatedFormat('l d-m-Y')]) }}
                                    {{ __('media.work.days') }}@if ($outside) {{ __('media.work.outside') }}@endif</p>
                                @if ($load && $load['extraDays'] > 0 && $suggested)
                                    <p class="hint workhint busy"><x-ic n="users" />{{ trans_choice('media.work.busy', $load['extraDays'], ['n' => $load['requests'], 'days' => $load['extraDays'], 'date' => $suggested->translatedFormat('l d-m-Y')]) }}</p>
                                @endif
                            @endif

                        </div>
                        @if ($rush)
                            <div class="rushbox full">
                                <span class="t"><x-ic n="flame" />{{ __('media.rush.title') }}</span>
                                <span class="hint">{{ __('media.rush.text') }}</span>
                                <label for="mr-rush" class="lbl">{{ __('media.f.rushReason') }}<span class="req" aria-hidden="true">*</span></label>
                                <textarea id="mr-rush" class="inp" rows="2" wire:model.blur="rush_reason" {{ $err('rush_reason') }}></textarea>
                                @error('rush_reason')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                            </div>
                        @endif
                        @if ($program_id && ! $editingId)
                            <div class="banner soft full"><x-ic n="shield" /><span>{{ __($twoApprovals ? 'media.path.note2' : 'media.path.note1') }}</span></div>
                        @endif
                    </div>
                @endif

                {{-- 4. Files and extras --}}
                @if ($step === 4)
                    <h3 class="sth"><x-ic n="clip" /><x-media.bi k="media.wz.s4t" /></h3>
                    <div class="fgrid">
                        @foreach ($extras as $field)
                            @include('livewire.media-requests.partials.field', ['field' => $field])
                        @endforeach

                        <div class="field full">
                            <label for="mr-desc"><x-media.bi k="media.f.desc" /></label>
                            <p class="hint">{{ __('media.h.desc') }}</p>
                            <textarea id="mr-desc" class="inp" rows="4" wire:model.blur="description"></textarea>
                            <button type="button" class="btn-q proofbtn" x-on:click="$dispatch('media-proofread-open', { text: document.getElementById('mr-desc').value, target: 'description' })"><x-ic n="check" />{{ __('media.proof.check') }}</button>
                        </div>

                        {{-- Checking the text is up to the requester; a reminder, never a block. Delivery is in the app, so nothing to choose. --}}
                        @unless ($service === '000')
                            <div class="banner soft full proofhint">
                                <x-ic n="check" />
                                <span><b>{{ __('media.wz.proofTitle') }}</b> {{ __('media.wz.proofText') }}
                                    <button type="button" class="btn-q" x-on:click="$dispatch('media-proofread-open', { text: document.getElementById('mr-desc')?.value ?? '', target: 'description' })">{{ __('media.wz.proofGo') }}</button></span>
                            </div>
                        @endunless

                        <div class="field full">
                            <span class="lbl"><x-media.bi k="media.f.attach" /></span>
                            <p class="hint">{{ __('media.h.attach') }}</p>
                            <label class="drop">
                                <span class="di"><x-ic n="upload" /></span>
                                <b>{{ __('media.as.drop') }}</b>
                                <span class="sub">{{ __('media.as.max') }}</span>
                                <input type="file" multiple wire:model="uploads" class="vh">
                            </label>
                            <div wire:loading wire:target="uploads" class="sub"><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span></div>
                            @if ($uploads)
                                <ul class="files">
                                    @foreach ($uploads as $u)<li><span><x-ic n="file" /><span class="ltr">{{ $u->getClientOriginalName() }}</span></span></li>@endforeach
                                </ul>
                            @endif
                            @error('uploads.*')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endif
            </div>

            <div class="mf">
                @if ($step > ($editingId ? 2 : 1))
                    <button type="button" class="btn btn-o" wire:click="back"><x-ic n="back" class="flip" />{{ __('media.wz.back') }}</button>
                @else
                    <button type="button" class="btn btn-g" wire:click="close">{{ __('media.wz.cancel') }}</button>
                @endif
                <button type="submit" class="btn btn-p" wire:loading.attr="data-loading">
                    <span class="spin"></span>
                    {{ $step === 4 ? __($editingId ? 'media.wz.save' : 'media.wz.send') : __('media.wz.next') }}
                    @if ($step < 4)<x-ic n="arrow" class="flip" />@else<x-ic n="send" class="flip" />@endif
                </button>
            </div>
        </form>
    </div>
</div>
