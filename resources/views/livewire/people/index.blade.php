@php($locale = app()->getLocale())

<div>
    <div class="ph">
        <div>
            <h1>{{ __('media.nav.people') }}</h1>
            <p class="lead">{{ __('media.people.lead') }}</p>
        </div>
        @unless ($editing)
            <button type="button" class="btn btn-p" wire:click="create"><x-ic n="plus" />{{ __('media.people.add') }}</button>
        @endunless
    </div>

    {{-- The password just made: shown once, to pass on to the person. --}}
    @if ($madePassword)
        <div class="banner soft madepw" role="status">
            <x-ic n="check" />
            <span>{{ __('media.people.made', ['email' => $madeEmail]) }}
                <b class="ltr num pw">{{ $madePassword }}</b>
                <button type="button" class="btn-q" x-data x-on:click="navigator.clipboard.writeText(@js($madeEmail.' / '.$madePassword)); $el.textContent = @js(__('media.proof.copied'))">{{ __('media.proof.copy') }}</button>
                <small class="sub">{{ __('media.people.madeOnce') }}</small></span>
            <button type="button" class="iconbtn" wire:click="forgetPassword" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
        </div>
    @endif

    {{-- Quick add, for trying the app: an email, a name and one of three roles. --}}
    @unless ($editing)
        <form wire:submit="quickAdd" class="card quickadd" x-data="{ role: @entangle('quickRole') }">
            <h2><x-ic n="plus" />{{ __('media.people.quick') }}</h2>
            <p class="sub">{{ __('media.people.quickLead') }}</p>
            <div class="seg" role="radiogroup" aria-label="{{ __('media.people.role') }}">
                @foreach (['media' => 'people.qr.media', 'staff' => 'people.qr.staff', 'pm' => 'people.qr.pm'] as $k => $label)
                    <button type="button" role="radio" :aria-checked="role === @js($k)" :class="{ on: role === @js($k) }" x-on:click="role = @js($k)">{{ __('media.'.$label) }}</button>
                @endforeach
            </div>
            <div class="fgrid" style="margin-top:12px">
                <div class="field">
                    <label for="q-email">{{ __('media.login.email') }}</label>
                    <input id="q-email" class="inp" type="email" dir="ltr" wire:model="quickEmail" autocomplete="off" placeholder="name@hcsyria.org">
                    @error('quickEmail')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="q-name">{{ __('media.people.quickName') }}</label>
                    <input id="q-name" class="inp" dir="auto" wire:model="quickName" autocomplete="off">
                    @error('quickName')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field" x-show="role === 'media'" x-cloak>
                    <label for="q-spec">{{ __('media.spec.title') }}</label>
                    <select id="q-spec" class="inp" wire:model="quickSpecialty">
                        <option value="Designer">{{ __('media.spec.design') }} (Designer)</option>
                        <option value="Photographer">{{ __('media.spec.photo') }} (Photographer)</option>
                        <option value="Videographer">{{ __('media.spec.video') }} (Videographer)</option>
                        <option value="Videographer / Editor">{{ __('media.spec.video') }} + {{ __('media.spec.montage') }} (Videographer / Editor)</option>
                        <option value="Video Editor">{{ __('media.spec.montage') }} (Video Editor)</option>
                    </select>
                </div>
                <fieldset class="field full" x-show="role === 'pm'" x-cloak style="border:0;padding:0;margin:0">
                    <legend style="font-weight:700;margin-bottom:6px">{{ __('media.people.manages') }}</legend>
                    <div class="row" style="gap:8px;flex-wrap:wrap">
                        @foreach ($allPrograms as $p)
                            <label class="check ltr"><input type="checkbox" value="{{ $p->id }}" wire:model="quickPrograms">{{ $p->code }} {{ $p->slug }}</label>
                        @endforeach
                    </div>
                    @error('quickPrograms')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </fieldset>
            </div>
            <button type="submit" class="btn btn-p" style="margin-top:12px"><x-ic n="plus" />{{ __('media.people.quickGo') }}</button>
        </form>
    @endunless
    @if ($editing)
        <form wire:submit="save" class="card" style="margin-bottom:20px">
            <h2>{{ $employeeId ? __('media.people.edit') : __('media.people.add') }}</h2>
            <div class="fgrid">
                @foreach ([['first_name_ar', 'people.firstAr', 'rtl'], ['last_name_ar', 'people.lastAr', 'rtl'], ['first_name_en', 'people.firstEn', 'ltr'], ['last_name_en', 'people.lastEn', 'ltr']] as [$f, $label, $dir])
                    <div class="field">
                        <label for="p-{{ $f }}">{{ __('media.'.$label) }}</label>
                        <input id="p-{{ $f }}" class="inp" dir="{{ $dir }}" wire:model="{{ $f }}" @error($f) aria-invalid="true" @enderror>
                        @error($f)<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div class="field">
                    <label for="p-email">{{ __('media.login.email') }}</label>
                    <input id="p-email" class="inp ltr" type="email" wire:model="email" @error('email') aria-invalid="true" @enderror>
                    @error('email')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="p-pw">{{ __('media.login.password') }}</label>
                    <input id="p-pw" class="inp ltr" type="password" wire:model="password" autocomplete="new-password" placeholder="{{ $employeeId ? __('media.people.keepPassword') : '' }}">
                    @error('password')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="p-role">{{ __('media.people.role') }}</label>
                    <select id="p-role" class="inp" wire:model="role">
                        @foreach (['employee', 'manager', 'general-manager', 'super-admin'] as $r)<option value="{{ $r }}">{{ __('media.people.roles.'.$r) }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="p-media">{{ __('media.people.mediaRole') }}</label>
                    <select id="p-media" class="inp" wire:model="media_role">
                        <option value="">{{ __('media.people.notTeam') }}</option>
                        <option value="member">{{ __('media.people.member') }}</option>
                        <option value="manager">{{ __('media.owner.media_manager') }}</option>
                    </select>
                </div>
                <div class="field full">
                    <label for="p-title">{{ __('media.people.position') }}</label>
                    <input id="p-title" class="inp" type="text" maxlength="255" wire:model.live.debounce.400ms="position_title" lang="en" dir="auto" placeholder="Videographer / Editor / Photographer (on need)">
                    <p class="hint">{{ __('media.spec.hint') }}</p>
                    @if ($media_role && trim($position_title) !== '')
                        <div class="row" style="gap:6px;margin-top:6px">
                            <span class="sub">{{ __('media.spec.title') }}:</span>
                            <x-media.skills :title="$position_title" />
                        </div>
                    @endif
                </div>
                <div class="field">
                    <label for="p-hours">{{ __('media.people.hours') }}</label>
                    <input id="p-hours" class="inp ltr" type="text" maxlength="11" wire:model="work_hours" placeholder="10:00-14:00">
                    <p class="hint">{{ __('media.people.hoursHint') }}</p>
                    @error('work_hours')<p class="err"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="p-city">{{ __('media.f.city') }}</label>
                    <select id="p-city" class="inp" wire:model="city_id">
                        <option value="">—</option>
                        @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $locale === 'ar' ? $c->name_ar : $c->name_en }}</option>@endforeach
                    </select>
                </div>
                <fieldset class="field full">
                    <legend>{{ __('media.people.manages') }}</legend>
                    <div class="choices" dir="ltr">
                        @foreach ($allPrograms as $p)
                            <label class="choice"><input type="checkbox" value="{{ $p->id }}" wire:model="programs"><span><x-ic n="check" class="ck" />{{ $p->code }} {{ $p->slug }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="btnline">
                <button type="submit" class="btn btn-p" wire:loading.attr="data-loading"><span class="spin"></span>{{ __('media.wz.save') }}</button>
                <button type="button" class="btn btn-g" wire:click="cancel">{{ __('media.wz.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="tablewrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">{{ __('media.people.name') }}</th>
                    <th scope="col">{{ __('media.people.role') }}</th>
                    <th scope="col">{{ __('media.people.mediaRole') }}</th>
                    <th scope="col">{{ __('media.people.manages') }}</th>
                    <th scope="col"><span class="vh">{{ __('media.people.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($people as $e)
                    <tr wire:key="e-{{ $e->id }}" @if (! $e->is_active) style="opacity:.5" @endif>
                        <td data-l="{{ __('media.people.name') }}"><b>{{ $e->nameForLocale($locale) }}</b><br><small class="sub ltr">{{ $e->account?->email }}</small></td>
                        <td data-l="{{ __('media.people.role') }}">{{ $e->account ? __('media.people.roles.'.$e->account->role) : '—' }}</td>
                        <td data-l="{{ __('media.people.mediaRole') }}">
                            @if ($e->media_role)
                                <span class="chip {{ $e->media_role === 'manager' ? 'work' : '' }}">{{ $e->media_role === 'manager' ? __('media.owner.media_manager') : __('media.people.member') }}</span>
                                <x-media.skills :e="$e" />
                                <small class="sub" style="display:block;margin-top:4px">{{ $e->city ? ($locale === 'ar' ? $e->city->name_ar : $e->city->name_en) : '' }}@if ($e->work_hours) · {{ __('media.people.partTime') }} <span class="num ltr">{{ $e->work_hours }}</span>@endif</small>
                            @else — @endif
                        </td>
                        <td data-l="{{ __('media.people.manages') }}" class="ltr" style="text-align:start">{{ $e->managedPrograms->map(fn ($p) => $p->code.' '.$p->slug)->implode(', ') ?: '—' }}</td>
                        <td style="white-space:nowrap;text-align:end">
                            <button type="button" class="btn-q" wire:click="edit({{ $e->id }})"><x-ic n="edit" />{{ __('media.people.edit') }}</button>
                            @if ($e->id !== auth()->user()->employee_id)
                                <button type="button" class="btn-q" wire:click="toggleActive({{ $e->id }})">{{ $e->is_active ? __('media.people.disable') : __('media.people.enable') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
