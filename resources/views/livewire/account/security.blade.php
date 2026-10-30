<div x-data="{ name: window.mrPasskeys.deviceName(), busy: false, failed: false, supported: window.mrPasskeys.supported(),
        async add() {
            this.busy = true; this.failed = false;
            try { await window.mrPasskeys.add(this.name); await $wire.$refresh(); }
            catch (e) { if (e.status === 423) { await $wire.$refresh(); } else if (! window.mrPasskeys.cancelled(e)) { this.failed = true; } }
            this.busy = false;
        },
        async remove(id) {
            this.failed = false;
            try { await window.mrPasskeys.remove(id); await $wire.$refresh(); }
            catch (e) { if (e.status === 423) { await $wire.$refresh(); } else { this.failed = true; } }
        } }">
    <div class="ph">
        <div>
            <h1>{{ __('media.passkey.title') }}</h1>
            <p class="lead">{{ __('media.passkey.lead') }}</p>
        </div>
    </div>

    <div class="card stack" style="gap:14px;max-width:620px">
        <p class="err" role="alert" x-show="! supported" x-cloak><x-ic n="alert" />{{ __('media.passkey.unsupported') }}</p>
        <p class="err" role="alert" x-show="failed" x-cloak><x-ic n="alert" />{{ __('media.passkey.failed') }}</p>

        @if ($passkeys->isNotEmpty())
            <ul class="list">
                @foreach ($passkeys as $key)
                    <li class="fr" wire:key="pk-{{ $key->id }}">
                        <x-ic n="shield" />
                        <span style="flex:1;min-width:0">
                            <b style="display:block">{{ $key->name }}</b>
                            <small class="sub">{{ __('media.passkey.added', ['date' => $key->created_at->format('d-m-Y')]) }}@if ($key->last_used_at) · {{ __('media.passkey.used', ['date' => $key->last_used_at->format('d-m-Y')]) }}@endif</small>
                        </span>
                        @if ($confirmed)
                            <button type="button" class="btn" x-on:click="if (confirm(@js(__('media.passkey.removeAsk')))) remove({{ $key->id }})">{{ __('media.passkey.remove') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="sub">{{ __('media.passkey.none') }}</p>
        @endif

        @if ($confirmed)
            <form class="stack" style="gap:8px" x-on:submit.prevent="add()" x-show="supported">
                <div class="field">
                    <label for="pk-name">{{ __('media.passkey.name') }}</label>
                    <input id="pk-name" class="inp" type="text" maxlength="60" required x-model="name">
                </div>
                <button type="submit" class="btn btn-p" :disabled="busy"><x-ic n="plus" />{{ __('media.passkey.add') }}</button>
            </form>
        @else
            <form wire:submit="confirmPassword" class="stack" style="gap:8px" x-show="supported">
                <div class="field">
                    <label for="pk-password">{{ __('media.passkey.confirm') }}</label>
                    <input id="pk-password" class="inp ltr" type="password" wire:model="password" autocomplete="current-password" required @error('password') aria-invalid="true" @enderror>
                    @error('password')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn btn-p" wire:loading.attr="data-loading"><span class="spin"></span>{{ __('media.passkey.continue') }}</button>
            </form>
        @endif
    </div>
</div>
