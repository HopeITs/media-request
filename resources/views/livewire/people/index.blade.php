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
                    <input id="p-title" class="inp" type="text" maxlength="120" wire:model.live.debounce.400ms="position_title" lang="en" dir="auto" placeholder="Communication team member / Photographer">
                    <p class="hint">{{ __('media.spec.hint') }}</p>
                    @php($found = app(\App\Support\MediaRequests\MediaTeam::class)->skillsFromTitle($position_title))
                    @if ($media_role && $found)
                        <div class="row" style="gap:6px;margin-top:6px">
                            <span class="sub">{{ __('media.spec.title') }}:</span>
                            @foreach ($found as $s)<span class="chip"><x-ic :n="config('media-requests.specialties.'.$s.'.icon')" />{{ __('media.spec.'.$s) }}</span>@endforeach
                        </div>
                    @endif
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
                            @if ($e->media_role)<span class="chip {{ $e->media_role === 'manager' ? 'work' : '' }}">{{ $e->media_role === 'manager' ? __('media.owner.media_manager') : __('media.people.member') }}</span>@foreach (app(\App\Support\MediaRequests\MediaTeam::class)->skillsOf($e) as $s)<span class="chip"><x-ic :n="config('media-requests.specialties.'.$s.'.icon')" />{{ __('media.spec.'.$s) }}</span>@endforeach @else — @endif
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
