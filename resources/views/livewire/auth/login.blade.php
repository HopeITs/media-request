{{-- Block form on purpose: the one-line form before a later block breaks Blade's compiler. --}}
@php
    $locale = app()->getLocale();
@endphp

<div class="stack" style="gap:22px">
    <div>
        <img src="{{ asset('img/hope-center.svg') }}" alt="Christian Hope Center" style="height:46px;width:auto">
    </div>

    <div>
        <h1>{{ __('media.app') }}<small class="beta">Beta</small></h1>
        <p class="lead">{{ __('media.login.lead') }}</p>
    </div>

    <form wire:submit="login" class="card stack" style="gap:4px">
        <div class="field">
            <label for="email">{{ __('media.login.email') }}</label>
            <input id="email" class="inp ltr" type="email" wire:model="email" autocomplete="username" required @error('email') aria-invalid="true" @enderror>
            @error('email')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
        </div>
        <div class="field">
            <label for="password">{{ __('media.login.password') }}</label>
            <input id="password" class="inp ltr" type="password" wire:model="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-p btn-lg btn-w" wire:loading.attr="data-loading"><span class="spin"></span>{{ __('media.login.btn') }}</button>
    </form>

    @if ($demo->isNotEmpty())
        <section aria-labelledby="demo-title" class="stack" style="gap:10px">
            <div>
                <h2 id="demo-title" style="font-size:16px;font-weight:800">{{ __('media.login.demo') }}</h2>
                <p class="sub">{{ __('media.login.demoHint') }}</p>
            </div>
            <ul class="list">
                @foreach ($demo as $u)
                    @php
                        $role = match (true) {
                            $u->role === 'super-admin' => __('media.people.roles.super-admin'),
                            $u->profile?->media_role === 'manager' => __('media.owner.media_manager'),
                            $u->profile?->media_role === 'member' => __('media.owner.team'),
                            (bool) $u->profile?->managedPrograms()->exists() => __('media.owner.program_manager'),
                            default => __('media.owner.requester'),
                        };
                    @endphp
                    <li>
                        <button type="button" class="fr" style="width:100%;border:0;background:none;text-align:start"
                                x-on:click="$wire.set('email', '{{ $u->email }}', false); $wire.set('password', 'password', false); $wire.login()">
                            <span class="av sm">{{ mb_strtoupper(mb_substr($u->profile?->first_name_en ?? 'U', 0, 1)) }}</span>
                            <span style="flex:1;min-width:0"><b style="display:block">{{ $u->profile?->nameForLocale($locale) }}</b><small class="sub">{{ $role }}</small></span>
                            <x-ic n="arrow" class="flip" style="color:var(--text-3)" />
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
