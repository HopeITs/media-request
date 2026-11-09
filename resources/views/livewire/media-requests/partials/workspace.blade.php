{{--
    The workspace (public/js/workspace.js): pages the home screen leads to open here, over it,
    as tabs, instead of taking the person away. Each tab is the page itself in a frame of this
    site (data-ws-frame: shown without the site's own header and footer).
    wire:ignore: the home screen's own updates must never reload an open page.
--}}
<div x-data="mrWorkspace" data-home="{{ route('media-requests.home') }}" data-ws-panel wire:ignore
     class="ws" x-show="open" x-cloak
     x-transition:enter="ws-enter" x-transition:enter-start="ws-from" x-transition:enter-end="ws-to"
     x-transition:leave="ws-leave" x-transition:leave-start="ws-to" x-transition:leave-end="ws-from"
     role="region" aria-label="{{ __('media.ws.label') }}">
    <div class="ws-panel" x-ref="panel" tabindex="-1">
        <div class="ws-bar">
            <div class="ws-tabs" role="tablist" aria-label="{{ __('media.ws.tabs') }}">
                <template x-for="tab in tabs" :key="tab.id">
                    <div class="ws-tab" :class="{ on: tab.id === active }">
                        <button type="button" role="tab" class="ws-tab-name"
                                :id="'wst-' + tab.id" :aria-controls="'wsp-' + tab.id"
                                :aria-selected="(tab.id === active).toString()"
                                x-on:click="show(tab.id)"
                                x-text="tab.title || @js(__('media.ws.loading'))"></button>
                        <button type="button" class="ws-tab-x" x-on:click="close(tab.id)"
                                :aria-label="@js(__('media.ws.closeTab')) + ': ' + tab.title">
                            <x-ic n="x" />
                        </button>
                    </div>
                </template>
            </div>
            <div class="ws-actions">
                <button type="button" class="ws-btn" x-on:click="pop()" title="{{ __('media.ws.full') }}" aria-label="{{ __('media.ws.full') }}">
                    <x-ic n="arrow" class="flip-rtl" />
                </button>
                <button type="button" class="ws-btn" x-on:click="closeAll()" title="{{ __('media.ws.close') }}" aria-label="{{ __('media.ws.close') }}">
                    <x-ic n="x" />
                </button>
            </div>
        </div>
        <div class="ws-body">
            <template x-for="tab in tabs" :key="tab.id">
                {{-- src set once: following the page inside must not reload it. --}}
                <iframe data-ws-frame class="ws-frame" x-show="tab.id === active"
                        :id="'wsp-' + tab.id" role="tabpanel" :aria-labelledby="'wst-' + tab.id" :title="tab.title"
                        x-init="$el.src = tab.url" x-on:load="frameLoaded(tab, $el)"></iframe>
            </template>
        </div>
    </div>
</div>
