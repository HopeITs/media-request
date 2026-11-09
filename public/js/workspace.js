/*
 * The home screen's workspace: the person's work stays on the home screen.
 *
 * Any page the home screen leads to (a card's link, a shortcut, the top bar, a request)
 * opens here, in a panel over the home screen, instead of taking the person away. Each
 * page keeps its own server checks: it is the page itself, in a frame of this site
 * (data-ws-frame; layouts/head.blade.php hides the site's own header inside it, and
 * SecurityHeaders lets the site frame itself and nobody else).
 *
 *  - several pages at once, as tabs; a page already open is brought to the front;
 *  - the address bar follows (?ws=/media-requests/board), so a refresh reopens it and
 *    Back closes it; Esc or × goes back to the home screen as it was;
 *  - what has to leave stays a normal link: downloads, files, other sites, a new tab,
 *    sign-out, the language switch, and the home screen itself;
 *  - a page that is not a page of the app (the sign-in page after a session ended)
 *    takes the whole window.
 *
 * Used as x-data="mrWorkspace" (livewire/media-requests/partials/workspace.blade.php).
 */
(() => {
    const MAX_TABS = 8;
    // Paths that are files or actions, not pages: they keep their normal behaviour.
    const LEAVES = /\/(logout|lang|download|export|files?|attachments?)(\/|$|\?)|\.(pdf|xlsx?|csv|docx?|zip|png|jpe?g|svg|mp4)(\?|$)/i;
    let seq = 0;

    const workspace = () => ({
        tabs: [],   // { id, url, title }
        active: null,
        open: false,
        home: '/',

        init() {
            this.home = new URL(this.$el.dataset.home || '/', location.href).pathname.replace(/\/+$/, '');
            const onClick = (e) => this.intercept(e);
            document.addEventListener('click', onClick, true);
            const onPop = () => this.fromAddress(false);
            window.addEventListener('popstate', onPop);
            const onKey = (e) => { if (e.key === 'Escape' && this.open && ! e.defaultPrevented) this.closeAll(); };
            window.addEventListener('keydown', onKey);
            // Visits started by a script (a card's double-click, Livewire.navigate): the cancellable
            // livewire:navigate event. Back and Forward stay Livewire's (detail.history).
            const onNavigate = (e) => {
                const url = e.detail && e.detail.url;
                if (! url || e.detail.history || ! this.wants(typeof url === 'string' ? url : url.href)) return;
                e.preventDefault();
                this.visit(typeof url === 'string' ? url : url.href);
            };
            document.addEventListener('livewire:navigate', onNavigate);

            // Over the content exactly: under the top bar, the whole width.
            const place = () => {
                const bar = document.querySelector('header.top');
                this.$el.style.setProperty('--ws-top', Math.max(0, bar ? bar.getBoundingClientRect().bottom : 0) + 'px');
            };
            place();
            window.addEventListener('resize', place);
            this.$watch('open', (on) => { place(); document.documentElement.classList.toggle('ws-open', on); });

            this.teardown = () => {
                document.removeEventListener('click', onClick, true);
                window.removeEventListener('popstate', onPop);
                window.removeEventListener('keydown', onKey);
                window.removeEventListener('resize', place);
                document.removeEventListener('livewire:navigate', onNavigate);
                document.documentElement.classList.remove('ws-open');
            };
            document.addEventListener('livewire:navigating', () => this.teardown && this.teardown(), { once: true });

            this.fromAddress(true);
        },
        destroy() { this.teardown && this.teardown(); },
        teardown: null,

        // ---- which links open here ------------------------------------------
        url(href) { try { return new URL(href, location.href); } catch (e) { return null; } },
        isHome(u) { const p = u.pathname.replace(/\/+$/, '') || '/'; return p === '/' || p === this.home; },
        wants(href) {
            const u = this.url(href);
            if (! u || u.origin !== location.origin || this.isHome(u) || LEAVES.test(u.pathname)) return false;
            // A page inside a frame: never a frame inside a frame.
            if (document.documentElement.classList.contains('in-workspace')) return false;
            return true;
        },
        intercept(e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
            if (! a || a.closest('[data-ws-panel]') || a.hasAttribute('download') || a.dataset.wsSkip !== undefined) return;
            if (a.target && a.target !== '_self') return;
            const href = a.getAttribute('href');
            if (! href || href.startsWith('#') || href.startsWith('javascript:') || ! this.wants(a.href)) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            // A menu that opened the link (More, the phone sheet) closes behind it.
            const pop = a.closest('[popover]');
            try { if (pop && pop.matches(':popover-open')) pop.hidePopover(); } catch (err) {}
            this.visit(a.href);
        },

        // ---- tabs -------------------------------------------------------------
        path(href) { const u = this.url(href); return u ? u.pathname + u.search + u.hash : href; },
        visit(href, push = true) {
            const path = this.path(href);
            const same = this.tabs.find((t) => t.url === path);
            if (same) { this.show(same.id, push); return; }
            if (this.tabs.length >= MAX_TABS) this.tabs.shift();
            const tab = { id: 'ws' + ++seq, url: path, title: '' };
            this.tabs.push(tab);
            this.show(tab.id, push);
        },
        show(id, push = true) {
            this.active = id;
            this.open = true;
            this.address(push);
            this.$nextTick(() => this.$refs.panel && this.$refs.panel.focus({ preventScroll: true }));
        },
        close(id) {
            const i = this.tabs.findIndex((t) => t.id === id);
            if (i < 0) return;
            this.tabs.splice(i, 1);
            if (! this.tabs.length) { this.closeAll(); return; }
            if (this.active === id) this.active = this.tabs[Math.min(i, this.tabs.length - 1)].id;
            this.address(false);
        },
        closeAll() {
            this.tabs = [];
            this.active = null;
            this.open = false;
            this.address(true);
        },
        current() { return this.tabs.find((t) => t.id === this.active) || null; },
        // The page as a page of its own, outside the home screen: the one deliberate way out.
        pop() { const tab = this.current(); if (tab) window.location.assign(tab.url); },

        // ---- the frame tells us where it went ---------------------------------
        frameLoaded(tab, frame) {
            const follow = () => {
                let win;
                try { win = frame.contentWindow; void win.location.href; } catch (e) { return; }
                const u = new URL(win.location.href);
                if (u.href === 'about:blank') return;
                // Back on the home screen, from inside: that tab is done.
                if (this.isHome(u)) { this.close(tab.id); return; }
                // Not a page of the app (sign-in after the session ended, an error page): the whole window.
                if (! win.document.querySelector('#main')) { window.location.assign(u.href); return; }
                tab.url = u.pathname + u.search + u.hash;
                // Named as the menu names it (most pages share the app's title), else by its title.
                const here = win.document.querySelector('header.top a[aria-current="page"], .navmenu a[aria-current="page"]');
                const name = here ? here.textContent.trim() : (win.document.title || '').split(' · ')[0].trim();
                tab.title = name || tab.title;
                if (tab.id === this.active) this.address(false);
            };
            follow();
            try {
                frame.contentWindow.document.addEventListener('livewire:navigated', follow);
                frame.contentWindow.addEventListener('popstate', follow);
            } catch (e) {}
        },

        // ---- the address bar ----------------------------------------------------
        address(push) {
            const tab = this.current();
            const target = new URL(location.href);
            if (tab && this.open) target.searchParams.set('ws', tab.url);
            else target.searchParams.delete('ws');
            const next = target.pathname + target.search + target.hash;
            if (next === location.pathname + location.search + location.hash) return;
            // Our own state only: with no Livewire snapshot in it, Back and Forward between these
            // entries stay with us (popstate above) instead of Livewire restoring a page.
            const state = { mrWs: tab ? tab.url : null };
            if (push) history.pushState(state, '', next);
            else history.replaceState(state, '', next);
        },
        fromAddress(first) {
            const ws = new URL(location.href).searchParams.get('ws');
            if (ws && ws.startsWith('/') && ! ws.startsWith('//') && this.wants(ws)) {
                this.visit(ws, false);
            } else if (! first && this.open) {
                this.tabs = [];
                this.active = null;
                this.open = false;
            }
        },
    });

    const register = () => window.Alpine.data('mrWorkspace', workspace);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register);
})();
