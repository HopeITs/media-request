<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#161617" media="(prefers-color-scheme: dark)">
{{-- Light or dark as the person last chose (else the system's), set before the first paint so nothing flashes. --}}
<script>
    (function () {
        var root = document.documentElement;
        var stored = function () { try { var t = localStorage.getItem('mr-theme'); return t === 'light' || t === 'dark' ? t : null; } catch (e) { return null; } };
        var apply = function () { var t = stored(); if (t && root.dataset.theme !== t) root.dataset.theme = t; };
        apply();
        document.addEventListener('livewire:navigated', apply);   // page changes without a reload
        // Switched anywhere else on this device (the home screen around a page opened in it, another
        // tab): followed at once, so a framed page never stays in the old theme until a refresh.
        window.addEventListener('storage', function (e) {
            if (e.key !== 'mr-theme') return;
            var t = stored();
            if (t && root.dataset.theme !== t) { root.dataset.theme = t; window.dispatchEvent(new CustomEvent('mr-theme', { detail: t })); }
        });
    })();
    // A page opened inside the home screen (a workspace tab, or a page shown as a card: an iframe
    // marked data-ws-frame) is drawn without the site's own header, footer and phone bar.
    (function () {
        var inside = false;
        try { inside = window.self !== window.top && !! window.frameElement && window.frameElement.hasAttribute('data-ws-frame'); } catch (e) { inside = false; }
        if (! inside) return;
        var root = document.documentElement;
        var mark = function () { if (! root.classList.contains('in-workspace')) root.classList.add('in-workspace'); };
        mark();
        new MutationObserver(mark).observe(root, { attributes: true, attributeFilter: ['class'] });
        document.addEventListener('livewire:navigated', mark);
    })();
</script>
<title>{{ isset($title) && $title ? $title.' · ' : '' }}{{ __('media.app') }}</title>
<link rel="icon" href="{{ asset('img/hope-center.svg') }}" type="image/svg+xml">
<link rel="preload" href="{{ asset('fonts/SomarSans-Regular.ttf') }}" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js" defer></script>
{{-- Home screen: drag library (SortableJS 1.15.2, served locally) and the home-screen logic. Not deferred, so both exist before Alpine starts. --}}
<script src="{{ asset('js/sortable.min.js') }}"></script>
<script src="{{ asset('js/home-screen.js') }}?v={{ filemtime(public_path('js/home-screen.js')) }}"></script>
<script src="{{ asset('js/workspace.js') }}?v={{ filemtime(public_path('js/workspace.js')) }}"></script>
<script src="{{ asset('js/passkeys.js') }}?v={{ filemtime(public_path('js/passkeys.js')) }}"></script>
