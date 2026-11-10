/*
 * The home screen's tabs: the person's work stays on the home screen.
 *
 * Every page the person opens from the home screen (a card's link, a shortcut, the top bar,
 * a request) becomes a tab in a row above the cards instead of taking them away; the row
 * shows only while there are tabs, and with no tab in front the cards show. Each page keeps its own
 * server checks: it is the page itself, in a frame of this site (data-ws-frame;
 * layouts/head.blade.php hides the site's own header inside it, and SecurityHeaders lets
 * the site frame itself and nobody else).
 *
 *  - a page already open is brought to the front; up to 10 tabs;
 *  - the address bar follows (?ws=/media-requests/board): a refresh reopens it, Back
 *    goes to the tab before; Esc, Home, or the tab in front clicked again go to the cards;
 *  - the pin on a tab keeps it: kept tabs are there again the next time the home screen
 *    opens (on this device), loaded only when they are opened;
 *  - the Cards sheet chooses which pages of the top bar open here and which as a full page;
 *  - what has to leave stays a normal link: downloads, files, other sites, a new tab,
 *    sign-out, the language switch;
 *  - a page that is not a page of the app (the sign-in page after a session ended)
 *    takes the whole window.
 *
 * Used as x-data="mrWorkspace" (livewire/media-requests/partials/workspace.blade.php).
 */
(() => {
    const MAX_TABS = 10;
    // Paths that are files or actions, not pages: they keep their normal behaviour.
    const LEAVES = /\/(logout|lang|download|export|files?|attachments?)(\/|$|\?)|\.(pdf|xlsx?|csv|docx?|zip|png|jpe?g|svg|mp4)(\?|$)/i;
    let seq = 0;

    const workspace = () => ({
        tabs: [],         // { id, url, title, keep }
        active: null,     // null: the home tab
        home: '/',
        pages: [],        // the top bar's pages: { path, label }
        outside: [],      // paths of the pages that open as a full page

        init() {
            this.home = new URL(this.$el.dataset.home || '/', location.href).pathname.replace(/\/+$/, '');
            // The top bar's pages (layouts/app.blade.php puts them on <html>), for the gear's list.
            try { this.pages = JSON.parse(document.documentElement.dataset.pages || '[]'); } catch (e) { this.pages = []; }
            this.store = 'mr-ws-' + (document.documentElement.dataset.user || '0');
            const saved = this.read();
            this.outside = Array.isArray(saved.outside) ? saved.outside : [];
            for (const t of (Array.isArray(saved.kept) ? saved.kept : [])) {
                if (t && typeof t.url === 'string' && this.wants(t.url)) this.tabs.push({ id: 'ws' + ++seq, url: t.url, title: t.title || '', keep: true });
            }

            const onClick = (e) => this.intercept(e);
            document.addEventListener('click', onClick, true);
            const onPop = () => this.fromAddress(false);
            window.addEventListener('popstate', onPop);
            const onKey = (e) => {
                if (e.key !== 'Escape' || e.defaultPrevented) return;
                if (this.active) this.goHome();
            };
            window.addEventListener('keydown', onKey);
            // Visits started by a script (a card's double-click, Livewire.navigate): the cancellable
            // livewire:navigate event. Back and Forward stay Livewire's (detail.history).
            const onNavigate = (e) => {
                const url = e.detail && e.detail.url;
                const href = url && (typeof url === 'string' ? url : url.href);
                if (! href || e.detail.history || ! this.wants(href)) return;
                e.preventDefault();
                this.visit(href);
            };
            document.addEventListener('livewire:navigate', onNavigate);

            // The page fills the screen below the tabs.
            const place = () => {
                const body = this.$refs.body;
                if (body) this.$el.style.setProperty('--ws-top', Math.max(0, body.getBoundingClientRect().top + window.scrollY) + 'px');
            };
            window.addEventListener('resize', place);
            // The top bar marks the page in front: the open tab's page, else Home.
            const mark = () => {
                const tab = this.current();
                const path = tab ? new URL(tab.url, location.href).pathname.replace(/\/+$/, '') : this.home;
                // Each menu on its own: the top bar and the phone's bottom bar.
                for (const menu of document.querySelectorAll('header.top nav.nav, nav.tabbar')) {
                    const links = [...menu.querySelectorAll(':scope > a[href]')]
                        .map((a) => [a, new URL(a.href).pathname.replace(/\/+$/, ''), new URL(a.href).search]);
                    // The closest match only: /media-requests (My requests) must not light up for /media-requests/board.
                    const best = links.filter(([, p, q]) => (tab ? p !== this.home && (path === p || path.startsWith(p + '/')) : p === this.home && ! q))
                        .sort((x, y) => y[1].length - x[1].length)[0];
                    for (const [a] of links) {
                        if (best && a === best[0]) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
                    }
                }
            };
            this.$watch('active', (id) => {
                mark();
                document.documentElement.classList.toggle('ws-page', !! id);
                this.$nextTick(place);
            });

            this.teardown = () => {
                document.removeEventListener('click', onClick, true);
                window.removeEventListener('popstate', onPop);
                window.removeEventListener('keydown', onKey);
                window.removeEventListener('resize', place);
                document.removeEventListener('livewire:navigate', onNavigate);
                document.documentElement.classList.remove('ws-page');
            };
            document.addEventListener('livewire:navigating', () => this.teardown && this.teardown(), { once: true });

            this.fromAddress(true);
            this.$nextTick(place);
        },
        destroy() { this.teardown && this.teardown(); },
        teardown: null,

        // ---- kept on this device -----------------------------------------------
        read() { try { return JSON.parse(localStorage.getItem(this.store) || '{}') || {}; } catch (e) { return {}; } },
        write() {
            const kept = this.tabs.filter((t) => t.keep).map((t) => ({ url: t.url, title: t.title }));
            try { localStorage.setItem(this.store, JSON.stringify({ kept, outside: this.outside })); } catch (e) {}
        },
        toggleKeep(tab) { tab.keep = ! tab.keep; this.write(); },
        opensInside(path) { return ! this.outside.includes(path); },
        setInside(path, on) {
            this.outside = on ? this.outside.filter((p) => p !== path) : [...new Set([...this.outside, path])];
            this.write();
        },

        // ---- which links open here ------------------------------------------
        url(href) { try { return new URL(href, location.href); } catch (e) { return null; } },
        isHome(u) { const p = u.pathname.replace(/\/+$/, '') || '/'; return p === '/' || p === this.home; },
        wants(href) {
            const u = this.url(href);
            if (! u || u.origin !== location.origin || this.isHome(u) || LEAVES.test(u.pathname)) return false;
            // A page inside a frame: never a frame inside a frame.
            if (document.documentElement.classList.contains('in-workspace')) return false;
            // A page the person chose to open as a full page.
            const path = u.pathname.replace(/\/+$/, '');
            return ! this.outside.some((p) => path === p || path.startsWith(p + '/'));
        },
        intercept(e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
            if (! a || a.closest('[data-ws-panel]') || a.hasAttribute('download') || a.dataset.wsSkip !== undefined) return;
            if (a.target && a.target !== '_self') return;
            const href = a.getAttribute('href');
            if (! href || href.startsWith('#') || href.startsWith('javascript:')) return;
            // Home (the top bar, the logo) while a page is in front: back to the cards, no reload.
            const u = this.url(a.href);
            // The bell (?bell=1) and a request link (?open=12) open their panel here too, so the
            // tabs are never lost to a reload of the home screen.
            if (u && u.origin === location.origin && this.isHome(u) && ! document.documentElement.classList.contains('in-workspace')) {
                const bell = u.searchParams.get('bell'), open = parseInt(u.searchParams.get('open'), 10);
                const known = [...u.searchParams.keys()].every((k) => k === 'bell' || k === 'open');
                if (known && this.$wire) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (this.active) this.goHome();
                    if (bell) this.$wire.set('bellOpen', true);
                    if (open > 0) this.$wire.call('openRequest', open);
                    return;
                }
            }
            if (! this.wants(a.href)) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            this.visit(a.href);
        },
        // The menu that opened a link (More, the phone's sheet, the account menu) closes behind it:
        // the click was taken here, so its own handler never ran.
        closeMenus() {
            for (const pop of document.querySelectorAll('[popover]')) {
                try { if (pop.matches(':popover-open') && ! pop.closest('.tile')) pop.hidePopover(); } catch (err) {}
            }
            window.dispatchEvent(new CustomEvent('mr-ws-opened'));
        },

        // ---- tabs -------------------------------------------------------------
        path(href) { const u = this.url(href); return u ? u.pathname + u.search + u.hash : href; },
        visit(href, push = true) {
            const path = this.path(href);
            const same = this.tabs.find((t) => t.url === path);
            if (same) { this.show(same.id, push); return; }
            if (this.tabs.length >= MAX_TABS) {
                const drop = this.tabs.findIndex((t) => ! t.keep);
                this.tabs.splice(drop < 0 ? 0 : drop, 1);
            }
            const tab = { id: 'ws' + ++seq, url: path, title: '', keep: false };
            this.tabs.push(tab);
            this.show(tab.id, push);
        },
        show(id, push = true) {
            this.closeMenus();
            this.active = id;
            this.address(push);
            window.scrollTo({ top: 0 });
        },
        goHome(push = true) {
            this.closeMenus();
            this.active = null;
            this.address(push);
        },
        close(id) {
            const i = this.tabs.findIndex((t) => t.id === id);
            if (i < 0) return;
            const was = this.tabs[i];
            this.tabs.splice(i, 1);
            if (was.keep) this.write();
            if (this.active === id) {
                const next = this.tabs[Math.min(i, this.tabs.length - 1)];
                this.active = next ? next.id : null;
                this.address(false);
            }
        },
        current() { return this.tabs.find((t) => t.id === this.active) || null; },
        // The page as a page of its own, outside the home screen: the one deliberate way out.
        pop() { const tab = this.current(); if (tab) window.location.assign(tab.url); },

        // ---- the frame tells us where it went ---------------------------------
        frameLoaded(tab, frame) {
            const follow = () => {
                let win;
                try { win = frame.contentWindow; void win.location.href; } catch (e) { return; }
                if (win.location.href === 'about:blank') return;
                const u = new URL(win.location.href);
                // Back on the home screen, from inside: that tab is done, and the cards show.
                if (this.isHome(u)) { const was = this.active === tab.id; this.close(tab.id); if (was) this.goHome(false); return; }
                // Signed out (the session ended): the sign-in page takes the whole window. Any other
                // page without the app's frame (an error: no longer allowed, gone) stays in its tab.
                if (! win.document.querySelector('#main')) {
                    if (/\/login(\/|$)/.test(u.pathname)) window.location.assign(u.href);
                    else tab.title = (win.document.title || '').trim() || tab.title;
                    return;
                }
                const url = u.pathname + u.search + u.hash;
                // Gone inside to a page another tab already shows: that tab comes forward, this one closes.
                const twin = this.tabs.find((t) => t !== tab && t.url === url);
                if (twin && tab.url !== url) { const was = this.active === tab.id; this.close(tab.id); if (was) this.show(twin.id, false); return; }
                tab.url = url;
                // Named by the page's own heading (a request: its name), else as the menu names it,
                // else by its title (most pages share the app's title).
                // A request's heading: its name only, without the archive code (.mrc) or kind.
                const h1 = win.document.querySelector('#main h1 .mrt') || win.document.querySelector('#main h1');
                const here = win.document.querySelector('header.top a[aria-current="page"]');
                const name = (h1 && h1.textContent.trim()) || (here && here.textContent.trim()) || (win.document.title || '').split(' · ')[0].trim();
                tab.title = (name || tab.title).replace(/\s+/g, ' ').slice(0, 60);
                if (tab.keep) this.write();
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
            if (tab) target.searchParams.set('ws', tab.url);
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
            if (ws && ws.startsWith('/') && ! ws.startsWith('//') && this.wants(ws)) this.visit(ws, false);
            else if (! first) this.goHome(false);
        },
    });

    const register = () => window.Alpine.data('mrWorkspace', workspace);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register);
})();
