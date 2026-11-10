/*
 * Feedback while the app is being tried out (temporary; config media-requests.feedback).
 *
 *  - "Report" in the top bar (in the More sheet on a phone): it takes a screenshot of what is on screen (the page
 *    in front, a workspace tab included), then a short note is written and it is sent.
 *  - After an action (the server dispatches "mr-rate", or leaves one for the next page), a small
 *    card asks how it went: three faces, and an optional note. Ignored, it goes by itself.
 *
 * Everything goes to POST /feedback with the page's address; administrators read it on /feedback.
 * The settings and words come from #mr-fbk (layouts/app.blade.php). Inside a frame of the home
 * screen (a workspace tab, a floating card) there is no top bar: the window around it has one, and
 * a rating asked inside is passed up to it.
 */
(() => {
    const SHOT_LIB = 'https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.8/dist/html2canvas-pro.min.js';
    const root = document.documentElement;
    let inside = false;
    try { inside = window.self !== window.top && !! window.frameElement && window.frameElement.hasAttribute('data-ws-frame'); } catch (e) { inside = false; }

    // In a frame: a rating asked here is shown by the window around it, with this page's address.
    if (inside) {
        window.addEventListener('mr-rate', (e) => {
            try { window.parent.dispatchEvent(new CustomEvent('mr-rate', { detail: { ...(e.detail || {}), url: location.pathname + location.search } })); } catch (err) {}
        });
        return;
    }

    let cfg = null, t = {};
    const read = () => {
        const el = document.getElementById('mr-fbk');
        if (! el) return false;
        try { t = JSON.parse(el.dataset.t || '{}'); } catch (e) { t = {}; }
        cfg = { post: el.dataset.post, next: el.dataset.next ? JSON.parse(el.dataset.next) : null };
        return true;
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const here = () => {
        // The page in front: a workspace tab's page when one is open, else this one.
        const frame = root.classList.contains('ws-page') && document.querySelector('iframe[data-ws-frame]:not([hidden])');
        try { if (frame && frame.contentWindow) return { win: frame.contentWindow, url: frame.contentWindow.location.pathname + frame.contentWindow.location.search }; } catch (e) {}
        return { win: window, url: location.pathname + location.search };
    };
    const send = (body) => fetch(cfg.post, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ viewport: innerWidth + 'x' + innerHeight, ...body }),
    }).then((r) => r.ok);
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
    const icon = (name) => { const tpl = document.querySelector('#mr-fbk template[data-ic="' + name + '"]'); return tpl ? tpl.content.cloneNode(true) : document.createTextNode(''); };

    // ---- the screenshot ------------------------------------------------------------
    let lib = null;
    const loadLib = () => lib || (lib = new Promise((resolve, reject) => {
        if (window.html2canvas) { resolve(window.html2canvas); return; }
        const s = document.createElement('script');
        s.src = SHOT_LIB; s.async = true; s.crossOrigin = 'anonymous';
        s.onload = () => (window.html2canvas ? resolve(window.html2canvas) : reject(new Error('no html2canvas')));
        s.onerror = () => { lib = null; reject(new Error('not loaded')); };
        document.head.appendChild(s);
    }));
    // What is on screen of that window, as a JPEG no wider than 1600px. Null when it cannot be taken.
    const shoot = async (win) => {
        try {
            const h2c = await loadLib();
            const doc = win.document;
            const w = win.innerWidth, h = win.innerHeight;
            const canvas = await h2c(doc.body, {
                x: win.scrollX, y: win.scrollY, width: w, height: h, windowWidth: w, windowHeight: h,
                scale: Math.min(win.devicePixelRatio || 1, 1600 / w), logging: false, useCORS: true,
                backgroundColor: getComputedStyle(doc.body).backgroundColor || null,
                ignoreElements: (n) => n.classList && (n.classList.contains('fbk-panel') || n.classList.contains('fbk-rate')),
            });
            return canvas.toDataURL('image/jpeg', 0.72);
        } catch (e) { return null; }
    };

    // ---- reporting a problem ------------------------------------------------------------
    let panel = null;
    const closePanel = () => { if (panel) { panel.remove(); panel = null; } };
    const report = async (opener) => {
        if (panel) { closePanel(); return; }
        const where = here();
        opener.disabled = true;
        opener.classList.add('fbk-busy');
        // A moment first, so a menu that opened it (the phone's More sheet) is gone from the picture.
        await new Promise((r) => setTimeout(r, 250));
        const shot = await shoot(where.win);
        opener.disabled = false;
        opener.classList.remove('fbk-busy');

        panel = el('div', 'fbk-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', t.reportTitle || 'Report');
        const head = el('div', 'fbk-h');
        head.append(el('b', null, t.reportTitle));
        const x = el('button', 'fbk-x'); x.type = 'button'; x.setAttribute('aria-label', t.close || 'Close'); x.append(icon('x'));
        x.addEventListener('click', closePanel);
        head.append(x);
        panel.append(head, el('p', 'fbk-lead', t.reportLead));

        let keepShot = !! shot;
        if (shot) {
            const fig = el('figure', 'fbk-shot');
            const img = el('img'); img.src = shot; img.alt = t.shot || '';
            const drop = el('label', 'fbk-keep');
            const cb = el('input'); cb.type = 'checkbox'; cb.checked = true;
            cb.addEventListener('change', () => { keepShot = cb.checked; fig.classList.toggle('off', ! keepShot); });
            drop.append(cb, el('span', null, t.withShot));
            fig.append(img);
            panel.append(fig, drop);
        } else {
            panel.append(el('p', 'fbk-note', t.noShot));
        }
        const area = el('textarea', 'inp');
        area.rows = 4; area.maxLength = 3000; area.placeholder = t.reportPh || ''; area.dir = 'auto';
        panel.append(area);
        const err = el('p', 'fbk-err'); err.hidden = true;
        const row = el('div', 'fbk-b');
        const go = el('button', 'btn btn-p'); go.type = 'button'; go.append(icon('send'), document.createTextNode(t.send || 'Send'));
        row.append(go);
        panel.append(err, row);
        document.body.appendChild(panel);
        area.focus();

        go.addEventListener('click', async () => {
            const note = area.value.trim();
            if (! note) { err.textContent = t.needNote; err.hidden = false; area.focus(); return; }
            go.disabled = true;
            const ok = await send({ kind: 'report', url: where.url, note, screenshot: keepShot ? shot : null }).catch(() => false);
            if (ok) { closePanel(); thank(); } else { go.disabled = false; err.textContent = t.failed; err.hidden = false; }
        });
        panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); closePanel(); } });
    };

    // ---- rating what was just done --------------------------------------------------------
    let card = null, timer = null;
    const closeCard = () => { clearTimeout(timer); if (card) { card.remove(); card = null; } };
    const rate = (detail) => {
        if (! detail || ! detail.action) return;
        closeCard();
        const url = detail.url || here().url;
        card = el('div', 'fbk-rate');
        card.setAttribute('role', 'status');
        const q = el('p', 'fbk-q', (t.rateQ || 'How did it go?').replace(':what', detail.label || detail.action));
        const x = el('button', 'fbk-x'); x.type = 'button'; x.setAttribute('aria-label', t.close || 'Close'); x.append(icon('x'));
        x.addEventListener('click', closeCard);
        const head = el('div', 'fbk-h'); head.append(q, x);
        const faces = el('div', 'fbk-faces');
        [[1, 'frown', t.rate1], [2, 'meh', t.rate2], [3, 'smile', t.rate3]].forEach(([n, ic, label]) => {
            const b = el('button', 'fbk-face f' + n); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label);
            b.append(icon(ic), el('span', null, label));
            b.addEventListener('click', () => answer(n));
            faces.append(b);
        });
        card.append(head, faces);
        document.body.appendChild(card);
        // Not touched: it goes by itself.
        const later = () => { clearTimeout(timer); timer = setTimeout(closeCard, 15000); };
        later();
        card.addEventListener('pointerenter', () => clearTimeout(timer));
        card.addEventListener('pointerleave', () => { if (! card.classList.contains('open')) later(); });

        const answer = (n) => {
            clearTimeout(timer);
            card.classList.add('open');
            faces.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', b.classList.contains('f' + n) ? 'true' : 'false'));
            const sent = send({ kind: 'rating', rating: n, action: detail.action, url }).catch(() => false);
            // An optional word on it, most of all when it did not go well.
            let more = card.querySelector('.fbk-more');
            if (! more) {
                more = el('div', 'fbk-more');
                const area = el('textarea', 'inp'); area.rows = 2; area.maxLength = 3000; area.dir = 'auto';
                area.placeholder = n < 3 ? t.ratePhBad : t.ratePh;
                const row = el('div', 'fbk-b');
                const skip = el('button', 'btn btn-g btn-sm', t.skip); skip.type = 'button';
                const go = el('button', 'btn btn-p btn-sm', t.send); go.type = 'button';
                skip.addEventListener('click', () => { closeCard(); thank(); });
                go.addEventListener('click', async () => {
                    const note = area.value.trim();
                    if (note) { go.disabled = true; await sent; await send({ kind: 'rating', rating: n, action: detail.action, url, note }).catch(() => false); }
                    closeCard(); thank();
                });
                row.append(skip, go);
                more.append(area, row);
                card.append(more);
                area.focus();
            }
        };
    };

    const thank = () => {
        const n = el('div', 'fbk-thanks', t.thanks);
        n.setAttribute('role', 'status');
        document.body.appendChild(n);
        setTimeout(() => n.classList.add('out'), 1800);
        setTimeout(() => n.remove(), 2200);
    };

    // ---- opening it: the top bar's icon, or the row in the phone's More sheet -----------------
    // Not a floating button: one over the page sat on top of the forms' own buttons.
    document.addEventListener('click', (e) => {
        const opener = e.target instanceof Element && e.target.closest('[data-fbk-open]');
        if (! opener || ! cfg) return;
        e.preventDefault();
        report(opener);
    });
    const mount = () => {
        if (! read()) { cfg = null; return; }
        if (cfg.next) { const next = cfg.next; cfg.next = null; setTimeout(() => rate(next), 900); }
    };
    window.addEventListener('mr-rate', (e) => setTimeout(() => rate(e.detail), 700));
    document.addEventListener('livewire:navigated', () => { closePanel(); mount(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
