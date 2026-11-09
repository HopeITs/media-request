{{--
    The home screen's tabs (public/js/workspace.js): every page opened from the home screen
    becomes a tab here, shown below in a frame of this site (data-ws-frame: drawn without the
    site's own header and footer). No tab of its own for the home screen: with no tab chosen
    (Home in the top bar, the open tab clicked again, Esc) the cards show. The row shows only
    while there are tabs. wire:ignore: the home screen's own updates must never reload a page.
--}}
<div x-data="mrWorkspace" data-home="{{ route('media-requests.home') }}" data-ws-panel wire:ignore class="ws" :class="{ paged: active }" :hidden="! tabs.length" role="region" aria-label="{{ __('media.ws.label') }}">
    <div class="ws-bar">
        <div class="ws-tabs" role="tablist" aria-label="{{ __('media.ws.tabs') }}">
            <template x-for="tab in tabs" :key="tab.id">
                <div class="ws-tab" :class="{ on: tab.id === active, kept: tab.keep }">
                    <button type="button" role="tab" class="ws-tab-name"
                            :id="'wst-' + tab.id" :aria-controls="'wsp-' + tab.id"
                            :aria-selected="(tab.id === active).toString()"
                            x-on:click="tab.id === active ? goHome() : show(tab.id)" x-on:auxclick.prevent="if ($event.button === 1) close(tab.id)"
                            x-text="tab.title || @js(__('media.ws.loading'))"></button>
                    <button type="button" class="ws-tab-pin" x-on:click="toggleKeep(tab)" :aria-pressed="tab.keep.toString()"
                            :title="tab.keep ? @js(__('media.ws.kept')) : @js(__('media.ws.keep'))" :aria-label="(tab.keep ? @js(__('media.ws.kept')) : @js(__('media.ws.keep'))) + ': ' + tab.title">
                        <x-ic n="pin" />
                    </button>
                    <button type="button" class="ws-tab-x" x-on:click="close(tab.id)" :aria-label="@js(__('media.ws.closeTab')) + ': ' + tab.title">
                        <x-ic n="x" />
                    </button>
                </div>
            </template>
        </div>
        <div class="ws-actions">
            <button type="button" class="ws-btn" :hidden="! active" x-on:click="pop()" title="{{ __('media.ws.full') }}" aria-label="{{ __('media.ws.full') }}">
                <x-ic n="out" />
            </button>
        </div>
    </div>
    <div class="ws-body" x-ref="body" :hidden="! active">
        <template x-for="tab in tabs" :key="tab.id">
            {{-- Loaded the first time it is shown (kept tabs wait until opened); src set once, so following the page inside never reloads it. --}}
            <iframe data-ws-frame class="ws-frame" :hidden="tab.id !== active"
                    :id="'wsp-' + tab.id" role="tabpanel" :aria-labelledby="'wst-' + tab.id" :title="tab.title"
                    x-effect="if (tab.id === active && ! $el.getAttribute('src')) $el.src = tab.url" x-on:load="frameLoaded(tab, $el)"></iframe>
        </template>
    </div>
</div>
