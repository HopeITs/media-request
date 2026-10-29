/*
 * Media Requests home screen, phone style.
 *
 * A grid of cells (8 columns on a computer, 4 on a phone). Each tile is
 * C×R cells. Everything the person does happens in the browser straight away:
 *
 *   - long-press (or "Customise") starts editing: the tiles wiggle briefly
 *     and a faint grid of the cells appears behind them;
 *   - drag a tile to move it (SortableJS; the others slide aside);
 *   - drag the handle in a tile's corner to resize it cell by cell, between
 *     the card's own minimum and maximum; a label shows "2×3" while dragging;
 *     with the keyboard, focus the handle and use the arrow keys;
 *   - "×" removes, the pin keeps a tile first, "+" adds from the gallery.
 *
 * Changes to the grid animate (FLIP): tiles glide from their old place to
 * the new one. The layout is saved in the background (saveLayout is
 * renderless), so nothing reloads.
 *
 * Used as x-data="mrHomeScreen(layout, bounds)" on the home screen root.
 */
window.mrHomeScreen = function (layout, bounds, looks = {}) {
    const EASE = 'cubic-bezier(.2,.8,.2,1)';
    const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const parse = (size) => (size || '1x1').split('x').map((n) => parseInt(n, 10) || 1);
    // On a phone these cards take a fixed, light size whatever was saved: one row for the clock and
    // the HC_PACK search, room for the proofreader's box and button.
    const PHONE_SIZE = { clock: '4x1', pack: '4x1', proof: '4x4' };
    // Glides currently running, per tile element (kept outside Alpine's reactive state).
    const glides = new WeakMap();

    return {
        items: layout,
        bounds: bounds,
        looks: looks,
        cols: 8,
        editing: false,
        gallery: false,
        wiggling: false,
        resizing: null,       // key of the tile being resized
        resizeLabel: '',
        sortable: null,
        pressTimer: null,
        pressed: false,
        saveTimer: null,
        wiggleTimer: null,

        init() {
            this.readCols();
            window.addEventListener('resize', () => { this.readCols(); this.drawGuides(); });
            // The grid's own width decides 8 or 4 columns (a container query), so watch it too.
            if (window.ResizeObserver) new ResizeObserver(() => this.readCols()).observe(this.$refs.grid.parentElement);
            if (window.Sortable) {
                this.sortable = Sortable.create(this.$refs.grid, {
                    disabled: true,
                    animation: 200,
                    easing: EASE,
                    draggable: '.tile:not(.pinned)',
                    filter: '.tbtn, .grip',
                    preventOnFilter: false,
                    delayOnTouchOnly: true,
                    delay: 120,
                    // Our own drag image follows the pointer exactly (the browser's native
                    // drag image flickers), and a tile only swaps once the pointer is well
                    // inside its neighbour, so tiles of different sizes do not flip back
                    // and forth under the pointer.
                    forceFallback: true,
                    fallbackOnBody: true,
                    fallbackTolerance: 4,
                    swapThreshold: 0.6,
                    invertSwap: true,
                    invertedSwapThreshold: 0.6,
                    ghostClass: 'tile-ghost',
                    chosenClass: 'tile-chosen',
                    dragClass: 'tile-drag',
                    onStart: () => { this.stopWiggle(); this.$refs.grid.classList.add('dragging'); },
                    // Tiles are placed by number, so moving one in the list moves nothing by itself:
                    // follow the new order at once, and the others glide to where they will end up.
                    onChange: () => this.flip(() => this.followDom()),
                    onEnd: () => { this.$refs.grid.classList.remove('dragging'); this.syncOrder(); },
                });
            }
            // The wheel stays inside a card only where there is more to scroll there; elsewhere the
            // page scrolls. Checked as the pointer comes onto a card (its content may have changed).
            this.$refs.grid.addEventListener('pointerover', (e) => {
                const tile = e.target instanceof Element && e.target.closest('.tile');
                if (! tile) { this.overTile = null; return; }
                if (tile === this.overTile) return;
                this.overTile = tile;
                for (const el of tile.querySelectorAll('.wb, .decide, .bellscroll')) {
                    el.classList.toggle('scrolls', el.scrollHeight > el.clientHeight + 2 || el.scrollWidth > el.clientWidth + 2);
                }
            });
            // While editing, a click on empty space (not a tile, a control or an open sheet) means Done.
            // Both the press and the click must be outside, so ending a drag or a resize never counts.
            const outside = (t) => t instanceof Element && ! t.closest('.tile, button, a, input, select, textarea, label, dialog, .sheet-back');
            let downOut = false;
            window.addEventListener('pointerdown', (e) => {
                downOut = this.editing && outside(e.target);
            });
            window.addEventListener('click', (e) => {
                if (this.editing && downOut && outside(e.target) && ! this.gallery) { this.done(); window.mrHints.used('editOut'); }
                downOut = false;
            });
            this.$watch('editing', (on) => {
                if (this.sortable) this.sortable.option('disabled', ! on);
                this.$nextTick(() => this.drawGuides());
            });
        },

        // ---- grid --------------------------------------------------------
        // The column count comes from the CSS (8, or 4 on a narrow screen). Read the
        // variable itself: counting the drawn columns is wrong once tiles are placed
        // by number, because a tile past the last column makes the browser add columns.
        readCols() {
            if (! this.$refs.grid || ! this.$refs.grid.isConnected) return;   // the page was updated and this grid replaced
            const n = parseInt(getComputedStyle(this.$refs.grid).getPropertyValue('--cols'), 10);
            if (n > 0 && n !== this.cols) this.cols = n;
        },
        metrics() {
            const grid = this.$refs.grid;
            const cs = getComputedStyle(grid);
            const gap = parseFloat(cs.columnGap) || 0;
            const rowH = parseFloat(cs.gridAutoRows) || 100;
            const colW = (grid.clientWidth - gap * (this.cols - 1)) / this.cols;
            return { gap, rowH, colW, rtl: cs.direction === 'rtl' };
        },
        // The faint cells shown while editing, drawn behind the tiles in the same grid.
        drawGuides() {
            const g = this.$refs.guides;
            if (! g || ! this.$refs.grid || ! this.$refs.grid.isConnected) return;
            if (! this.editing) { g.innerHTML = ''; return; }
            const { gap, rowH } = this.metrics();
            const rows = Math.ceil((this.$refs.grid.scrollHeight + gap) / (rowH + gap)) + 1;
            const want = rows * this.cols;
            if (g.childElementCount !== want) {
                g.innerHTML = '';
                for (let i = 0; i < want; i++) g.appendChild(document.createElement('i'));
            }
        },

        // ---- state helpers used by the template -------------------------
        item(key) { return this.items.find((i) => i.key === key) || { size: '1x1', hidden: true, pinned: false }; },
        hidden(key) { return this.item(key).hidden; },
        pinned(key) { return this.item(key).pinned; },
        hiddenItems() { return this.items.filter((i) => i.hidden); },
        tileEl(key) { return this.$refs.grid.querySelector('[data-key="' + key + '"]'); },
        // Columns never exceed the grid (a wide card on a phone takes the full width).
        // An object, so only these two properties are set and x-show keeps control of display.
        // At rest each tile gets its packed place (gaps filled); while editing, plain spans,
        // so the DOM order SortableJS changes is what the person sees.
        span(key) {
            // The same places while customising as after: what is seen is what is saved.
            const p = this.packed[key];
            if (p) return { gridColumn: (p.x + 1) + ' / span ' + p.w, gridRow: (p.y + 1) + ' / span ' + p.h };
            const [c, r] = parse(this.item(key).size);
            return { gridColumn: 'span ' + Math.min(c, this.cols), gridRow: 'span ' + r };
        },
        /*
         * Where every visible tile goes, with no holes left beside the cards:
         *  1. place tiles in order, each in the first free spot that fits (like
         *     a phone's home screen, and CSS "dense");
         *  2. a card with empty cells beside it grows into them, then into empty
         *     cells below it, never past its own maximum.
         * Two orders are tried: the person's own, and the same cards with the
         * shortcuts dropped into the gaps between them afterwards. Whichever
         * leaves fewer empty cells wins (the person's order on a tie).
         * Only what is shown changes; the saved sizes stay as the person set them.
         */
        // A phone (4 columns) keeps the screen light: no slideshow, no New request shortcut (the
        // + is in the bottom bar), and a small digital clock. The saved layout is not changed.
        phone() { return this.cols <= 4; },
        shownHere(key) { return ! this.hidden(key) && ! (this.phone() && ['showcase', 'app.new'].includes(key)); },
        clockLook() { return this.phone() ? 'digital' : this.styleOf('clock'); },
        get packed() {
            // Cards with nothing in them right now (marked by the server) go last, one line high and
            // closed, so the screen starts with what has something; the saved order is not changed.
            // While customising they show whole, so they can still be moved and sized.
            const empty = (i) => ! this.editing && this.tileEl(i.key)?.dataset.empty === '1';
            const visible = this.items.filter((i) => this.shownHere(i.key))
                .map((i) => (empty(i) ? { ...i, size: Math.min(4, this.cols) + 'x1' } : (this.phone() && PHONE_SIZE[i.key] ? { ...i, size: PHONE_SIZE[i.key] } : i)));
            const full = visible.filter((i) => ! empty(i)), closed = visible.filter(empty);
            const flat = new Set(closed.map((i) => i.key));   // they stay one line: never grown taller
            const isApp = (i) => this.iconish(i.key, i.size);
            const own = this.packOrder([...full, ...closed], flat);
            const gaps = this.packOrder([...full.filter((i) => ! isApp(i)), ...full.filter(isApp), ...closed], flat);
            return gaps.holes < own.holes ? gaps.out : own.out;
        },
        packOrder(list, flat = new Set()) {
            const cols = this.cols;
            const used = [];   // used[row][col] = true
            const free = (x, y, w, h) => {
                if (x + w > cols) return false;
                for (let r = y; r < y + h; r++) for (let c = x; c < x + w; c++) if (used[r] && used[r][c]) return false;
                return true;
            };
            const take = (x, y, w, h) => { for (let r = y; r < y + h; r++) { used[r] = used[r] || []; for (let c = x; c < x + w; c++) used[r][c] = true; } };
            const out = {};
            // Shortcuts, and cards made so small they show as an icon: placed, never grown.
            const icons = new Set(list.filter((it) => ! flat.has(it.key) && this.iconish(it.key, it.size)).map((it) => it.key));

            // A one-row card followed by shortcuts (the ask bar and its apps) takes the rest of
            // that row, so the cards after it start on a clean row instead of squeezing in beside it.
            const fill = {};
            list.forEach((it, i) => {
                if (icons.has(it.key) || it.fixed) return;
                const [w, h] = parse(it.size);
                if (h !== 1) return;
                let n = 0;
                while (list[i + 1 + n] && icons.has(list[i + 1 + n].key)) n++;
                const max = Math.min((this.bounds[it.key] || { max: [w, 1] }).max[0], cols);
                // Too many to fit beside it (a phone): it takes the whole row and they go below.
                if (n) fill[it.key] = Math.min(max, w + n <= cols ? Math.max(w, cols - n) : cols);
            });

            let floor = null;   // the closed cards start below everything else, not in a gap above
            for (const it of list) {
                let [w, h] = parse(it.size);
                w = Math.min(fill[it.key] || w, cols);
                if (flat.has(it.key) && floor === null) floor = used.length;
                let y = flat.has(it.key) ? floor : 0, x = 0;
                for (;; y++) { x = 0; while (x + w <= cols && ! free(x, y, w, h)) x++; if (x + w <= cols) break; }
                take(x, y, w, h);
                out[it.key] = { x, y, w, h };
            }

            const rows = used.length;
            const grow = (key, dw, dh) => {
                const p = out[key], max = (this.bounds[key] || { max: [1, 1] }).max;
                if (p.w + dw > Math.min(max[0], cols) || p.h + dh > max[1] || p.y + p.h + dh > rows) return false;
                const nx = dw ? p.x + p.w : p.x, ny = dh ? p.y + p.h : p.y;
                if (! free(nx, ny, dw || p.w, dh || p.h)) return false;
                take(nx, ny, dw || p.w, dh || p.h);
                p.w += dw; p.h += dh;
                return true;
            };
            // Shortcuts that end a row with empty cells after them move to the row's end,
            // so the card before them can grow into the space instead.
            for (let y = 0; y < rows; y++) {
                let end = cols;
                while (end > 0 && ! (used[y] && used[y][end - 1])) end--;
                const k = cols - end;
                if (! k) continue;
                const run = Object.keys(out)
                    .filter((key) => icons.has(key) && out[key].y === y && out[key].x < end)
                    .sort((a, b) => out[b].x - out[a].x);
                const moving = [];
                let edge = end;
                for (const key of run) {
                    if (out[key].x !== edge - 1) break;
                    moving.push(key);
                    edge--;
                }
                // Only worth it when a card sits just before them, one row high here, to take the space.
                const before = edge > 0 && Object.keys(out).find((key) => ! icons.has(key)
                    && out[key].y === y && out[key].h === 1 && out[key].x + out[key].w === edge);
                if (! moving.length || ! before) continue;
                for (const key of moving) {
                    used[y][out[key].x] = false;
                    out[key].x += k;
                    used[y][out[key].x] = true;
                }
            }

            // A card the person sized by hand keeps that size; the others grow into the gaps.
            const fixed = new Set(list.filter((it) => it.fixed).map((it) => it.key));
            const cards = Object.keys(out).filter((k) => ! icons.has(k) && ! fixed.has(k));
            for (const k of cards) while (grow(k, 1, 0));
            for (const k of cards) if (! flat.has(k)) while (grow(k, 0, 1));

            let holes = 0;
            for (let y = 0; y < rows; y++) for (let x = 0; x < cols; x++) if (! (used[y] && used[y][x])) holes++;
            return { out, holes };
        },
        // ---- a card made small: an icon that opens it -------------------
        // A card below the smallest size it can show itself at (bounds.card) is drawn as an icon.
        iconish(key, size) {
            if (key.startsWith('app.')) return true;
            const c = (this.bounds[key] || {}).card;
            if (! c) return false;
            const [w, h] = parse(size);
            return Math.min(w, this.cols) < Math.min(c[0], this.cols) || h < c[1];
        },
        mini(key) {
            if (key.startsWith('app.') || (! this.editing && this.tileEl(key)?.dataset.empty === '1')) return false;
            const p = ! this.editing && this.packed[key];
            return this.iconish(key, p ? p.w + 'x' + p.h : this.item(key).size);
        },
        /*
         * One click on the icon: the card opens in a side panel (a sheet from the bottom on a phone).
         * Two clicks: its own page. The panel is the card itself, shown as a popover (the browser's
         * top layer), so it is not moved in the page and its buttons keep working. It stays put while
         * the person works on the page (no fade, no closing on a click elsewhere); its ×, Escape or
         * the same icon closes it, and another icon shows that card in the same place.
         */
        peek: null,
        // The card a tile shows in the panel: a widget's own card, or a shortcut's data card.
        panelOf(key) { return this.tileEl(key)?.querySelector(':scope > .w, :scope > .appcard'); },
        // A shortcut on a computer: one click shows its card in the panel (a new-tab or modified click still opens the page).
        // Called from the grid's capture listener. The shortcut links have no wire:navigate (it would
        // follow the link as the button comes up), so the page is opened here: on a phone at once.
        appClick(e) {
            const link = e.target instanceof Element && e.target.closest('.tile.is-app > a.app');
            if (! link || this.editing || e.ctrlKey || e.metaKey || e.shiftKey || e.button > 0) return false;
            const key = link.parentElement.dataset.key;
            e.preventDefault();
            e.stopPropagation();
            if (this.phone() || ! this.panelOf(key)) {
                if (link.target === '_blank') window.open(link.href, '_blank', 'noopener');
                else if (window.Livewire && Livewire.navigate) Livewire.navigate(link.href);
                else window.location = link.href;
            } else {
                this.peekSoon(e, key);
            }
            return true;
        },
        // One click opens the panel only once it is clear no second click follows (a double-click opens
        // the page), so the panel never lands on the icon between the two clicks.
        peekSoon(e, key) {
            clearTimeout(this.clickTimer);
            if (e.detail > 1) return;
            this.clickTimer = setTimeout(() => this.peekOpen(key), 240);
        },
        peekOpen(key) {
            const w = this.panelOf(key);
            if (this.editing || ! w || ! w.showPopover) return;
            if (this.peek === key) { this.closePeek(); return; }
            // The panel stays where it is: another icon only changes what it shows.
            this.closePeek();
            try { w.showPopover(); } catch (e) { return; }
            this.peek = key;
            w.focus({ preventScroll: true });
        },
        closePeek() {
            const w = this.peek && this.panelOf(this.peek);
            this.peek = null;
            try { if (w && w.matches(':popover-open')) w.hidePopover(); } catch (e) {}
        },
        // The second click of a double-click: open the card's page, if it has one.
        miniPage(key) {
            const tile = this.tileEl(key);
            const link = tile && tile.querySelector(':scope > a.app');
            const page = tile && (tile.dataset.page || (link && link.href));
            clearTimeout(this.clickTimer);
            if (this.editing || this.phone()) return;
            if (! page) { this.peekOpen(key); return; }   // nothing to open: the panel, as one click would
            this.closePeek();
            window.mrHints.used('dblclick');
            if (link && link.target === '_blank') window.open(page, '_blank', 'noopener');   // HC Generator, another site
            else if (window.Livewire && Livewire.navigate) Livewire.navigate(page);
            else window.location = page;
        },
        // Cards with more than one look (the clock: digital or analogue).
        styleOf(key) { return this.item(key).style || (this.looks[key] || [])[0] || ''; },
        cycleStyle(key) {
            const list = this.looks[key] || [];
            if (list.length < 2) return;
            this.item(key).style = list[(list.indexOf(this.styleOf(key)) + 1) % list.length];
            this.save();
        },
        sizeText(key) { const [c, r] = parse(this.item(key).size); return Math.min(c, this.cols) + '×' + r; },
        clamp(key, c, r) {
            const b = this.bounds[key] || { min: [1, 1], max: [1, 1] };
            const maxC = Math.min(b.max[0], this.cols);
            return [Math.max(Math.min(b.min[0], maxC), Math.min(maxC, c)), Math.max(b.min[1], Math.min(b.max[1], r))];
        },

        // ---- editing ----------------------------------------------------
        startEdit(byPress = false) {
            this.closePeek();
            this.flip(() => { this.bake(); this.editing = true; });
            // One tip for this moment: how to start next time (if they used the button), else how to finish.
            if (byPress) window.mrHints.used('longpress');
            this.$nextTick(() => setTimeout(() => {
                // From the grid: $root would be the button pressed, which is gone by now.
                const root = this.$refs.grid.closest('[data-hint-press]'), t = root.dataset, at = root.querySelector('.hello .btn-p');
                (! byPress && window.mrHints.show('longpress', at, t.hintPress)) || window.mrHints.show('editOut', at, t.hintOut);
            }, 450));
            // The tiles wiggle for about a second to show they can move, then rest.
            this.wiggling = true;
            clearTimeout(this.wiggleTimer);
            this.wiggleTimer = setTimeout(() => { this.wiggling = false; }, 1150);
            // A short buzz on phones, only after a real tap (browsers refuse it otherwise).
            try { if (navigator.vibrate && navigator.userActivation && navigator.userActivation.isActive) navigator.vibrate(12); } catch (e) {}
        },
        /*
         * Editing starts from exactly what is on screen: each tile takes the size and
         * place it is shown at (after packing and gap filling), and the order becomes
         * the on-screen order. Nothing jumps when "Customise" is pressed, and what the
         * person then moves or resizes is what they saw. Saved only once they change something.
         */
        bake() {
            const p = this.packed;
            const at = (i) => (p[i.key] ? p[i.key].y * 1000 + p[i.key].x : Infinity);
            this.items.sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || at(a) - at(b));
            for (const it of this.items) {
                if (p[it.key]) it.size = p[it.key].w + 'x' + p[it.key].h;
            }
            for (const it of this.items) {
                const el = this.tileEl(it.key);
                if (el) this.$refs.grid.appendChild(el);
            }
        },
        // Any movement ends the wiggle at once, so it never fights a drag or a resize.
        stopWiggle() { clearTimeout(this.wiggleTimer); this.wiggling = false; },
        done() { this.flip(() => { this.editing = false; }); this.gallery = false; this.stopWiggle(); },

        // Double-click on a card that has a page opens it (not while editing, nor on its own controls).
        // A card with a search form (HC_PACK) opens with what is typed.
        openPage(e, tile) {
            if (this.editing || e.target.closest('input, textarea, select, button, a, label')) return;
            try { window.getSelection().removeAllRanges(); } catch (err) {}
            window.mrHints.used('dblclick');
            const form = tile.querySelector('form[method="GET"]');
            if (form) form.submit();
            else if (window.Livewire && Livewire.navigate) Livewire.navigate(tile.dataset.page);
            else window.location = tile.dataset.page;
        },

        // Long-press anywhere on a tile (not while typing in it) starts editing.
        press(e) {
            if (this.editing || e.button > 0 || ! (e.target instanceof Element) || e.target.closest('input, textarea, select')) return;
            this.pressed = false;
            clearTimeout(this.pressTimer);
            this.pressTimer = setTimeout(() => { this.pressed = true; this.startEdit(true); }, 520);
        },
        release() { clearTimeout(this.pressTimer); },
        // A long-press must not also open the link under the finger.
        swallowClick(e) {
            if (this.pressed) { e.preventDefault(); e.stopPropagation(); this.pressed = false; }
            else if (this.appClick(e)) return;
            else if (this.editing && e.target.closest('a') && ! e.target.closest('.tbtn, .grip')) { e.preventDefault(); }
        },

        remove(key) {
            this.flip(() => { this.item(key).hidden = true; this.item(key).pinned = false; });
            this.save();
        },
        add(key) {
            const i = this.items.findIndex((x) => x.key === key);
            if (i < 0) return;
            this.flip(() => {
                const [it] = this.items.splice(i, 1);
                it.hidden = false;
                this.items.push(it);
                const el = this.tileEl(key);
                if (el) this.$refs.grid.appendChild(el);
            });
            if (this.hiddenItems().length === 0) this.gallery = false;
            this.save();
        },
        togglePin(key) {
            const it = this.item(key);
            this.flip(() => {
                it.pinned = ! it.pinned;
                const el = this.tileEl(key);
                if (it.pinned && el) this.$refs.grid.prepend(el);
            });
            this.syncOrder();
        },
        setCells(key, c, r) {
            [c, r] = this.clamp(key, c, r);
            const size = c + 'x' + r;
            if (size === this.item(key).size) return false;
            this.flip(() => { this.item(key).size = size; this.item(key).fixed = true; }, key);
            return true;
        },

        // ---- resize: drag the corner grip, cell by cell -----------------
        resizeStart(e, key) {
            if (e.button > 0) return;
            e.preventDefault();
            e.stopPropagation();
            const tile = this.tileEl(key);
            const m = this.metrics();
            const rect = tile.getBoundingClientRect();
            const start = { x: e.clientX, y: e.clientY, w: rect.width, h: rect.height };
            let changed = false;

            this.stopWiggle();
            this.resizing = key;
            this.resizeLabel = this.sizeText(key);
            e.target.setPointerCapture && e.target.setPointerCapture(e.pointerId);

            const move = (ev) => {
                // The grip sits at the end corner: dragging outward (right in English, left in Arabic) grows the tile.
                const dx = (ev.clientX - start.x) * (m.rtl ? -1 : 1);
                const dy = ev.clientY - start.y;
                const rawC = (start.w + dx + m.gap) / (m.colW + m.gap);
                const rawR = (start.h + dy + m.gap) / (m.rowH + m.gap);
                // A margin around each cell edge: the size changes only once the pointer is
                // clearly into the next cell, so it never flickers between two sizes.
                const [curC, curR] = parse(this.item(key).size);
                const step = (raw, cur) => (raw > cur + 0.6 ? Math.floor(raw + 0.4) : raw < cur - 0.6 ? Math.ceil(raw - 0.4) : cur);
                if (this.setCells(key, step(rawC, Math.min(curC, this.cols)), step(rawR, curR))) changed = true;
                this.resizeLabel = this.sizeText(key);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                this.resizing = null;
                if (changed) this.save();
                this.$nextTick(() => this.drawGuides());
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        },
        // Keyboard: arrow keys on the grip add or remove a column or a row.
        resizeKey(e, key) {
            const [c, r] = parse(this.item(key).size);
            const grow = getComputedStyle(this.$refs.grid).direction === 'rtl' ? -1 : 1;
            const step = { ArrowRight: [grow, 0], ArrowLeft: [-grow, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] }[e.key];
            if (! step) return;
            e.preventDefault();
            if (this.setCells(key, c + step[0], r + step[1])) this.save();
        },

        // ---- smooth layout changes (FLIP) --------------------------------
        /*
         * FLIP without jitter:
         *  1. note where each tile is *seen* now (mid-glide included);
         *  2. apply the change;
         *  3. stop any glide still running, so the new places are measured
         *     without it (measuring through a running glide made tiles jump);
         *  4. glide each tile from where it was seen to its new place.
         * A tile that is still gliding therefore continues smoothly from
         * where it is, instead of restarting from somewhere else.
         */
        flip(mutate, resizedKey = null) {
            const visible = () => [...this.$refs.grid.querySelectorAll('.tile')].filter((t) => t.offsetParent !== null);
            const before = new Map(visible().map((t) => [t, t.getBoundingClientRect()]));
            mutate();
            this.$nextTick(() => {
                this.drawGuides();
                const tiles = visible();
                for (const t of tiles) {
                    const running = glides.get(t);
                    if (running) { running.cancel(); glides.delete(t); }
                }
                if (reducedMotion()) return;
                for (const t of tiles) {
                    const a = before.get(t);
                    const b = t.getBoundingClientRect();
                    let anim = null;
                    if (! a) {
                        anim = t.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: EASE });
                    } else if (t.dataset.key !== resizedKey) {
                        // The tile being resized follows the finger cell by cell; only the others glide.
                        const dx = a.left - b.left, dy = a.top - b.top;
                        if (Math.abs(dx) >= 1 || Math.abs(dy) >= 1) {
                            anim = t.animate([{ transform: `translate3d(${dx}px, ${dy}px, 0)` }, { transform: 'translate3d(0, 0, 0)' }], { duration: 240, easing: EASE });
                        }
                    }
                    if (anim) {
                        glides.set(t, anim);
                        anim.onfinish = () => { if (glides.get(t) === anim) glides.delete(t); };
                    }
                }
            });
        },

        // The DOM order is the truth after a drag; hidden tiles keep their place.
        syncOrder() {
            this.followDom();
            this.save();
        },
        followDom() {
            const order = [...this.$refs.grid.querySelectorAll('.tile')].map((t) => t.dataset.key);
            this.items.sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
            this.items.sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0));
        },

        save() {
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.$wire.saveLayout(JSON.parse(JSON.stringify(this.items))), 350);
        },
    };
};

/*
 * Example requests typed into an empty request box, one after another, so people
 * see what they can write ("I need a poster for Study Zone…"). Only the placeholder
 * changes: it stops while the box has focus or text, and shows one still example
 * when the person prefers less motion.
 *
 * Used as x-data="mrTypewriter(examples)" on the input or textarea itself.
 */
window.mrTypewriter = function (examples) {
    return {
        list: examples || [],
        timer: null,
        init() {
            if (! this.list.length) return;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { this.$el.placeholder = this.list[0]; return; }
            let i = 0, n = 0, back = false;
            const tick = () => {
                const el = this.$el;
                if (! el.isConnected) return;
                // Wait quietly while the person is in the box or has written something.
                if (document.activeElement === el || el.value) { el.placeholder = this.list[i]; this.timer = setTimeout(tick, 600); return; }
                const text = this.list[i];
                n += back ? -1 : 1;
                el.placeholder = text.slice(0, n) + (n < text.length || back ? '|' : '');
                let wait = back ? 28 : 60 + Math.random() * 50;
                if (! back && n >= text.length) { back = true; wait = 1700; }
                else if (back && n <= 0) { back = false; i = (i + 1) % this.list.length; wait = 400; }
                this.timer = setTimeout(tick, wait);
            };
            this.timer = setTimeout(tick, 500);
        },
        destroy() { clearTimeout(this.timer); },
    };
};

/*
 * A confirm dialog in the app's own look (light and dark, both languages), in
 * place of the browser's "localhost says" box. Returns a promise of true/false.
 *
 *   mrConfirm('Reject this request?', { ok: 'Reject', cancel: 'Back', danger: true })
 *       .then((yes) => yes && $wire.reject())
 *
 * Escape or a click outside counts as "no"; focus starts on the safe button.
 */
window.mrConfirm = function (message, opts = {}) {
    return new Promise((resolve) => {
        const d = document.createElement('dialog');
        d.className = 'mrconfirm';
        d.setAttribute('aria-label', message);
        const p = document.createElement('p');
        p.textContent = message;
        const row = document.createElement('div');
        row.className = 'mrconfirm-b';
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn btn-o';
        no.textContent = opts.cancel || document.documentElement.dataset.back || 'Back';
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'btn btn-p' + (opts.danger ? ' danger' : '');
        yes.textContent = opts.ok || 'OK';
        row.append(no, yes);
        d.append(p, row);
        document.body.appendChild(d);

        let answered = false;
        const done = (v) => { if (answered) return; answered = true; d.close(); d.remove(); resolve(v); };
        no.addEventListener('click', () => done(false));
        yes.addEventListener('click', () => done(true));
        d.addEventListener('cancel', (e) => { e.preventDefault(); done(false); });
        d.addEventListener('click', (e) => { if (e.target === d) done(false); });
        d.showModal();
        no.focus();
    });
};

/*
 * A short celebration when a request is delivered: a check drawn in a ring, with a
 * small burst in the brand colours, then it fades (about 1.6 s). Click or Escape skips it.
 * With "reduce motion" it is a still check that leaves quickly. Fired by the
 * "media-celebrate" event (from Livewire), or called directly: mrCelebrate(label).
 */
window.mrCelebrate = function (label, boost = false) {
    if (document.querySelector('.celebrate')) return;
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const el = document.createElement('div');
    el.className = 'celebrate' + (still ? ' still' : '') + (boost ? ' boost' : '');
    el.setAttribute('role', 'status');
    let bits = '';
    // Same-day delivery: twice the burst, and further.
    const count = boost ? 28 : 14;
    for (let i = 0; i < count; i++) {
        const a = (360 / count) * i + (i % 2 ? 8 : -8);
        const d = (boost ? 95 : 70) + (i % 3) * (boost ? 26 : 18);
        bits += '<i style="--a:' + a + 'deg;--d:' + d + 'px;--t:' + (i % 3) + '"></i>';
    }
    el.innerHTML = '<div class="cel-box"><div class="cel-burst">' + bits + '</div>'
        + (boost
            ? '<svg viewBox="0 0 24 24" class="cel-rocket" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>'
            : '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="cel-ring" cx="26" cy="26" r="23"/><path class="cel-check" d="M15 27l7 7 15-16"/></svg>')
        + (label ? '<b class="cel-label"></b>' : '') + '</div>';
    if (label) el.querySelector('.cel-label').textContent = label;
    document.body.appendChild(el);
    const done = () => { el.classList.add('out'); setTimeout(() => el.remove(), 260); window.removeEventListener('keydown', esc); };
    const esc = (e) => { if (e.key === 'Escape') done(); };
    el.addEventListener('click', done);
    window.addEventListener('keydown', esc);
    setTimeout(done, still ? 900 : (boost ? 2100 : 1600));
};
window.addEventListener('media-celebrate', (e) => window.mrCelebrate(e.detail && e.detail.label, !! (e.detail && e.detail.boost)));

/*
 * Quiet hints: a small tip next to something the person has not found yet
 * ("double-click to open", "click empty space to finish"). They stay out of the way:
 *   - shown only when the mouse rests on the thing, or at the moment it matters;
 *   - one resting hint per page, gone by itself after a few seconds or on any click;
 *   - at most three times each, and never again once the person uses it or presses "Got it".
 * Remembered per person in this browser.
 *
 *   <div data-hint="key" data-hint-text="…">   shown when the mouse rests on it
 *   mrHints.show('key', el, text)               shown now (the moment it matters)
 *   mrHints.used('key')                         found it: never shown again
 */
window.mrHints = (() => {
    const MAX = 3, REST = 900, STAY = 6500;
    const slot = () => 'mr.hints.' + (document.documentElement.dataset.user || '0');
    const read = () => { try { return JSON.parse(localStorage.getItem(slot())) || {}; } catch (e) { return {}; } };
    const write = (s) => { try { localStorage.setItem(slot(), JSON.stringify(s)); } catch (e) {} };
    const wanted = (key) => { const h = read()[key]; return ! h || (! h.done && (h.n || 0) < MAX); };
    let restedOnce = false, bubble = null, timer = null, rest = null, over = null;

    const hide = () => {
        clearTimeout(timer);
        if (! bubble) return;
        const b = bubble;
        bubble = null;
        b.classList.add('out');
        setTimeout(() => b.remove(), 200);
    };
    const used = (key) => {
        const s = read();
        s[key] = { ...(s[key] || {}), done: true };
        write(s);
        if (bubble && bubble.dataset.key === key) hide();
    };
    // `at` is where the mouse rests ({x, y}); the tip then sits by the pointer, not mid-card.
    const show = (key, el, text, at = null) => {
        if (! text || ! el || ! el.isConnected || ! wanted(key)) return false;
        hide();
        const s = read();
        s[key] = { n: ((s[key] || {}).n || 0) + 1 };
        write(s);

        const b = document.createElement('div');
        b.className = 'mrhint';
        b.dataset.key = key;
        b.setAttribute('role', 'status');
        const t = document.createElement('span');
        t.textContent = text;
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.textContent = document.documentElement.dataset.gotIt || 'Got it';
        ok.addEventListener('click', () => used(key));
        b.append(t, ok);
        document.body.appendChild(b);

        // Above the thing when there is room, else below; always inside the screen.
        const r = at ? { left: at.x, width: 0, top: at.y - 8, bottom: at.y + 20 } : el.getBoundingClientRect(), w = b.offsetWidth, h = b.offsetHeight;
        const below = r.top < h + 70;
        b.classList.toggle('below', below);
        const x = Math.max(8, Math.min(window.innerWidth - w - 8, r.left + r.width / 2 - w / 2));
        b.style.left = x + 'px';
        b.style.top = (below ? Math.min(r.bottom, window.innerHeight - h - 16) + 10 : r.top - h - 10) + 'px';
        b.style.setProperty('--ax', Math.max(14, Math.min(w - 14, r.left + r.width / 2 - x)) + 'px');
        bubble = b;
        timer = setTimeout(hide, STAY);
        return true;
    };

    // The mouse resting (not moving) on a thing with a hint. Touch gets its hints at the right moment instead.
    let pointer = null;
    document.addEventListener('pointermove', (e) => {
        if (e.pointerType && e.pointerType !== 'mouse') return;
        pointer = { x: e.clientX, y: e.clientY };
        clearTimeout(rest);
        const el = e.target instanceof Element ? e.target.closest('[data-hint]') : null;
        over = el;
        if (! el || restedOnce || bubble || el.closest('.editing') || ! wanted(el.dataset.hint)) return;
        rest = setTimeout(() => {
            if (over === el && el.matches(':hover') && show(el.dataset.hint, el, el.dataset.hintText, pointer)) restedOnce = true;
        }, REST);
    }, { passive: true });
    document.addEventListener('pointerdown', (e) => { clearTimeout(rest); if (bubble && ! bubble.contains(e.target)) hide(); }, true);
    window.addEventListener('scroll', hide, { passive: true, capture: true });
    document.addEventListener('livewire:navigated', () => { restedOnce = false; over = null; hide(); });

    return { show, used, hide };
})();
