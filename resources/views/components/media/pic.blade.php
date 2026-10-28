{{--
    Small pictures for choice tiles. Line drawings in currentColor so they
    follow the tile's text colour (neutral, red when chosen).

    name   picture key from config media-requests.fields (pics)
    mode   'sizes' draws paper outlines to scale, 'invite' shows how many
           cards come out of one A4 sheet
--}}
@props(['name', 'mode' => null])

@php
    // Paper sizes in mm, drawn inside a 48 × 48 box against the largest size.
    $paper = ['A1' => [594, 841], 'A2' => [420, 594], 'A3' => [297, 420], 'A4' => [210, 297], 'A5' => [148, 210], 'DL' => [99, 210]];
    $invite = ['dl' => [1, 3], 'a6' => [2, 2], 'a5' => [1, 2], 'folded' => [1, 2], 'square' => [1, 2], 'custom' => [0, 0]];
@endphp

<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
    @if ($mode === 'sizes' && isset($paper[$name]))
        @php
            [$w, $h] = $paper[$name];
            $s = 44 / 841 * 1.4;
            $pw = min(40, max(6, $w * $s));
            $ph = min(44, max(9, $h * $s));
        @endphp
        <rect x="{{ 24 - $pw / 2 }}" y="{{ 46 - $ph }}" width="{{ $pw }}" height="{{ $ph }}" rx="1"/>
    @elseif ($mode === 'invite' && isset($invite[$name]) && $invite[$name][0] > 0)
        @php
            [$cols, $rows] = $invite[$name];
        @endphp
        <rect x="11" y="3" width="26" height="42" rx="1"/>
        @for ($c = 1; $c < $cols; $c++)
            <line x1="{{ 11 + 26 / $cols * $c }}" y1="3" x2="{{ 11 + 26 / $cols * $c }}" y2="45" stroke-dasharray="2 2"/>
        @endfor
        @for ($r = 1; $r < $rows; $r++)
            <line x1="11" y1="{{ 3 + 42 / $rows * $r }}" x2="37" y2="{{ 3 + 42 / $rows * $r }}" stroke-dasharray="2 2"/>
        @endfor
    @else
        @switch($name)
            @case('post') <rect x="8" y="8" width="32" height="32" rx="3"/><circle cx="18" cy="19" r="3"/><path d="M10 36l10-10 7 7 5-5 6 6"/> @break
            @case('story') <rect x="14" y="4" width="20" height="40" rx="3"/><path d="M18 9h12"/> @break
            @case('carousel') <rect x="6" y="12" width="24" height="24" rx="2"/><path d="M34 14v20M40 16v16"/> @break
            @case('poster') <rect x="12" y="4" width="24" height="40" rx="1"/><path d="M17 12h14M17 18h10"/><rect x="17" y="24" width="14" height="12"/> @break
            @case('wide') <rect x="4" y="14" width="40" height="20" rx="2"/> @break
            @case('mailpic') <rect x="6" y="12" width="36" height="24" rx="2"/><path d="M6 14l18 12 18-12"/> @break
            @case('flyer') <rect x="12" y="4" width="24" height="40" rx="1"/><path d="M17 12h14M17 18h14M17 24h8"/> @break
            @case('bifold') <path d="M6 8h18v32H6zM24 8h18v32H24z"/> @break
            @case('trifold') <path d="M4 10h13v28H4zM17 10h14v28H17zM31 10h13v28H31z"/> @break
            @case('booklet') <path d="M24 10c-6-3-13-3-18 0v28c5-3 12-3 18 0 6-3 13-3 18 0V10c-5-3-12-3-18 0zM24 10v28"/> @break
            @case('banner') <rect x="4" y="16" width="40" height="16" rx="1"/><path d="M4 16l-2-3M44 16l2-3"/> @break
            @case('cardboard') <rect x="8" y="12" width="32" height="22" rx="2"/><path d="M14 20h10M14 26h16"/> @break
            @case('plastic') <rect x="8" y="12" width="32" height="22" rx="5"/><circle cx="17" cy="23" r="4"/><path d="M25 20h9M25 26h7"/> @break
            @case('blank') <rect x="4" y="10" width="40" height="28" rx="1"/><path d="M14 18h20"/> @break
            @case('names') <rect x="4" y="10" width="40" height="28" rx="1"/><path d="M14 18h20M12 26h24M18 31h12"/> @break
            @case('sheet') <rect x="11" y="4" width="26" height="40" rx="1"/><path d="M16 12h16M16 18h16M16 24h10"/> @break
            @case('phone') <rect x="14" y="4" width="20" height="40" rx="4"/><circle cx="24" cy="39" r="1.5"/> @break
            @case('phoneSheet') <rect x="4" y="6" width="22" height="36" rx="1"/><rect x="28" y="12" width="16" height="30" rx="3"/> @break
            @case('envelope') <rect x="4" y="12" width="40" height="26" rx="2"/><path d="M4 14l20 14 20-14"/> @break
            @case('youtube') <rect x="4" y="12" width="40" height="24" rx="4"/><path d="M21 19v10l8-5z"/> @break
            @case('gform') <rect x="10" y="4" width="28" height="40" rx="2"/><path d="M10 14h28M16 22h4M23 22h10M16 30h4M23 30h10"/> @break
            @case('profile') <circle cx="24" cy="24" r="18"/><circle cx="24" cy="20" r="6"/><path d="M13 37c3-6 19-6 22 0"/> @break
            @case('cover') <rect x="4" y="10" width="40" height="18" rx="2"/><circle cx="12" cy="32" r="6"/> @break
            @case('formpic') <rect x="10" y="4" width="28" height="40" rx="1"/><path d="M15 12h4M22 12h11M15 20h4M22 20h11M15 28h4M22 28h11"/> @break
            @case('vertical') <rect x="15" y="6" width="18" height="36" rx="2"/> @break
            @case('horizontal') <rect x="6" y="15" width="36" height="18" rx="2"/> @break
            @case('bothOr') <rect x="4" y="18" width="24" height="14" rx="2"/><rect x="31" y="8" width="13" height="26" rx="2"/> @break
            @default <rect x="8" y="8" width="32" height="32" rx="4"/><path d="M18 24h12M24 18v12"/>
        @endswitch
    @endif
</svg>
