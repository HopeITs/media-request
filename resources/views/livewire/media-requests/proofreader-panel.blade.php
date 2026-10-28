<div>
    @if ($inline)
        {{-- On the home screen card: the whole proofreader inside the tile, no pop-up. --}}
        <div class="proofinline">
            @include('livewire.media-requests.partials.proofreader-body')
        </div>
    @elseif ($show)
        <div class="mback" wire:click.self="close" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="modal proof" role="dialog" aria-modal="true" aria-labelledby="proof-t">
                <div class="mh">
                    <h2 id="proof-t"><x-ic n="check" />{{ __('media.proof.title') }}</h2>
                    <button type="button" class="iconbtn" wire:click="close" aria-label="{{ __('media.close') }}"><x-ic n="x" /></button>
                </div>

                @include('livewire.media-requests.partials.proofreader-body')
            </div>
        </div>
    @endif
</div>