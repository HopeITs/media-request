@php($locale = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <x-layouts.head />
</head>
<body style="background:var(--bg)">
    <main class="in" style="max-width:460px;min-height:100dvh;display:flex;flex-direction:column;justify-content:center;padding-block:40px">
        {{ $slot }}
    </main>
</body>
</html>
