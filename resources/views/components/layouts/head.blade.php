<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#161617" media="(prefers-color-scheme: dark)">
{{-- Light or dark as the person last chose (else the system's), set before the first paint so nothing flashes. --}}
<script>
    (function () {
        var apply = function () { try { var t = localStorage.getItem('mr-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {} };
        apply();
        document.addEventListener('livewire:navigated', apply);   // page changes without a reload
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
