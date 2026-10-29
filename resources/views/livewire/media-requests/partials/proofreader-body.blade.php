{{-- Inside of the proofreader: shared by the pop-up window and the home screen card. --}}
    {{-- Check a text, or go back to an earlier check. --}}
    <div class="prooftabs" role="tablist">
        <button type="button" role="tab" :aria-selected="@js($tab === 'check')" wire:click="again" @class(['on' => $tab === 'check'])><x-ic n="check" />{{ __('media.proof.tab.check') }}</button>
        <button type="button" role="tab" :aria-selected="@js($tab === 'history')" wire:click="$set('tab', 'history')" @class(['on' => $tab === 'history'])><x-ic n="clock" />{{ __('media.proof.tab.history') }}@if ($count)<span class="n num">{{ $count }}</span>@endif</button>
    </div>

    <div class="mb">
        @if ($tab === 'history')
            <p class="sub">{{ __('media.proof.historyLead') }}</p>
            @forelse ($history as $h)
                <div class="proofrow" wire:key="ph-{{ $h->id }}">
                    <button type="button" class="proofopen" wire:click="openSaved({{ $h->id }})">
                        <span class="t" dir="auto">{{ $h->preview() }}</span>
                        <small class="sub">
                            @if ($h->source !== 'text')<x-ic n="file" /><span class="ltr">{{ $h->source }}</span> · @endif
                            {{ $h->created_at->diffForHumans() }} · {{ trans_choice('media.proof.nChanges', count($h->result['changes'] ?? []), ['n' => count($h->result['changes'] ?? [])]) }}
                        </small>
                    </button>
                    <button type="button" class="iconbtn" aria-label="{{ __('media.proof.delete') }}" title="{{ __('media.proof.delete') }}"
                            x-on:click="mrConfirm(@js(__('media.proof.deleteConfirm')), { ok: @js(__('media.proof.delete')), cancel: @js(__('media.confirm.back')), danger: true }).then((yes) => yes && $wire.deleteSaved({{ $h->id }}))"><x-ic n="x" /></button>
                </div>
            @empty
                <div class="wempty" style="min-height:160px"><span class="ei"><x-ic n="clock" /></span><span>{{ __('media.proof.historyNone') }}</span></div>
            @endforelse
        @elseif ($result === null)
            @unless ($inline)<p class="sub">{{ __('media.proof.lead') }}</p>@endunless

            {{-- A Word or text file fills the box with its text. --}}
            <label class="proofdrop" for="proof-file{{ $inline ? '-card' : '' }}">
                <x-ic n="upload" />
                <span><b>{{ __('media.proof.upload') }}</b><small>{{ __('media.proof.uploadHint') }}</small></span>
                <input id="proof-file{{ $inline ? '-card' : '' }}" type="file" accept=".docx,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" wire:model="file" class="vh">
                <span class="spin" wire:loading wire:target="file"></span>
            </label>
            @error('file')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
            @if ($source !== 'text')<p class="hint"><x-ic n="file" /><span class="ltr">{{ $source }}</span></p>@endif

            <label for="proof-text{{ $inline ? '-card' : '' }}" class="vh">{{ __('media.proof.text') }}</label>
            <textarea id="proof-text{{ $inline ? '-card' : '' }}" class="inp" rows="9" dir="auto" maxlength="{{ \App\Support\MediaRequests\Proofreader::MAX_CHARS }}"
                      wire:model="text" placeholder="{{ __('media.proof.ph') }}"></textarea>
            @error('text')<p class="err" role="alert"><x-ic n="alert" />{{ $message }}</p>@enderror
            <p class="hint">
                @if ($aiAvailable){{ trans_choice('media.proof.left', $remaining, ['n' => $remaining]) }} <span class="proofpriv">{{ __('media.proof.privacy') }}</span>
                @else{{ __('media.proof.localOnly') }}@endif
            </p>
        @else
            {{-- Say first, and clearly, when spelling and grammar were not checked. --}}
            @if (! $result['ai'] && $result['aiError'])
                <div class="banner soft proofwarn"><x-ic n="alert" /><span><b>{{ __('media.proof.notChecked') }}</b> {{ __('media.proof.err.'.$result['aiError']) }}</span></div>
            @endif
            @if ($source !== 'text')<p class="hint"><x-ic n="file" /><span class="ltr">{{ $source }}</span></p>@endif

            {{-- The corrected text, with what changed marked; "show the original" adds back what was removed. --}}
            <div x-data="{ before: false }">
                <div class="proofdiff" dir="auto" aria-label="{{ __('media.proof.changed') }}">@foreach ($diff as [$part, $kind])@if ($kind === 'del')<del x-show="before" x-cloak>{{ $part }}</del>@elseif ($kind === 'add')<ins>{{ $part }}</ins>@else{{ $part }}@endif @endforeach</div>
                @if (collect($diff)->contains(fn ($d) => $d[1] !== 'same'))
                    <label class="check proofshow"><input type="checkbox" x-model="before">{{ __('media.proof.showBefore') }}</label>
                @endif
            </div>

            @if ($result['summary'])<p class="proofsum"><b>{{ $result['summary'] }}</b></p>@endif

            @if ($result['changes'])
                <h3 class="proofh">{{ __('media.proof.changes') }}</h3>
                <ol class="prooflist" dir="auto">
                    @foreach ($result['changes'] as $c)
                        <li><span class="b">{{ $c['before'] }}</span> <x-ic n="arrow" class="flip" /> <span class="a">{{ $c['after'] }}</span>@if ($c['note'])<small> · {{ $c['note'] }}</small>@endif</li>
                    @endforeach
                </ol>
            @endif

            @if ($result['suggestions'])
                <h3 class="proofh">{{ __('media.proof.suggestions') }}</h3>
                <ul class="prooflist" dir="auto">@foreach ($result['suggestions'] as $s)<li>{{ $s }}</li>@endforeach</ul>
            @endif

            @if ($result['localFixes'])
                <p class="hint"><x-ic n="check" />{{ __('media.proof.local', ['what' => collect($result['localFixes'])->map(fn ($f) => __('media.proof.fix.'.$f))->implode(app()->getLocale() === 'ar' ? '، ' : ', ')]) }}</p>
            @endif
            @if ($result['ai'] && ! $result['changes'] && $result['corrected'] === $result['original'])
                <p class="hint"><x-ic n="check" />{{ __('media.proof.clean') }}</p>
            @endif
            <p class="hint"><x-ic n="shield" />{{ __('media.proof.saved') }}</p>
        @endif
    </div>

    <div class="mf">
        @if ($tab === 'history')
            @unless ($inline)<button type="button" class="btn btn-o" wire:click="close">{{ __('media.close') }}</button>@else<span></span>@endunless
            <button type="button" class="btn btn-p" wire:click="again"><x-ic n="plus" />{{ __('media.proof.new') }}</button>
        @elseif ($result === null)
            @unless ($inline)<button type="button" class="btn btn-o" wire:click="close">{{ __('media.close') }}</button>@else<span></span>@endunless
            @if ($gemUrl = config('media-requests.proofread.gem_url'))
                {{-- The team's own Gem, in a new tab: the text is copied first, to paste there. --}}
                <a href="{{ $gemUrl }}" target="_blank" rel="noopener" class="btn btn-o proofgem" title="{{ __('media.proof.gemHint') }}" aria-label="{{ __('media.proof.gem') }}"
                   x-data x-on:click="navigator.clipboard && navigator.clipboard.writeText($wire.text || '')"><x-ic n="external" /><span class="pg-t">{{ __('media.proof.gem') }}</span></a>
            @endif
            <button type="button" class="btn btn-p" wire:click="check" wire:loading.attr="data-loading" wire:target="check, file">
                <span class="spin"></span><x-ic n="check" wire:loading.remove wire:target="check" />{{ __('media.proof.go') }}
            </button>
        @else
            <span class="btnline">
                <button type="button" class="btn btn-o" wire:click="again"><x-ic n="back" class="flip" />{{ __('media.proof.again') }}</button>
                <button type="button" class="btn btn-o" x-data x-on:click="navigator.clipboard.writeText(@js($result['corrected'])); $el.querySelector('span').textContent = @js(__('media.proof.copied'))"><x-ic n="file" /><span>{{ __('media.proof.copy') }}</span></button>
                @if ($savedId)<button type="button" class="btn btn-o" wire:click="download"><x-ic n="download" />Word</button>@endif
            </span>
            <button type="button" class="btn btn-p" wire:click="accept"><x-ic n="check" />{{ $target ? __('media.proof.use') : __('media.proof.keep') }}</button>
        @endif
    </div>
