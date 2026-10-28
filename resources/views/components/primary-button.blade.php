@props([
    'href'         => null,
    'full'         => false,           // set to true for w-full
    'type'         => 'submit',        // button type
    'showSpinner'  => true,            // show spinner when wire:loading
    'loadingText'  => null,            // e.g. __('general.loading').'…'
])

@php
    $base = 'py-2 px-4 text-body font-semibold text-white bg-primary cursor-pointer
             rounded-control shadow-e1 transition duration-150 ease-in-out flex justify-center items-center
             hover:bg-primary-hover active:scale-[0.98] active:shadow-none
             focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary
             disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100';
    $classes = ($full ? 'w-full ' : '') . $base;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button
        wire:loading.attr="disabled"
        {{ $attributes->merge(['class' => $classes, 'type' => $type]) }}
    >
        @if($showSpinner)
            <span role="status" class="me-2" wire:loading>
                <svg aria-hidden="true" class="inline size-4 animate-spin fill-white/90" viewBox="0 0 100 101" xmlns="http://www.w3.org/2000/svg">
                    <path d="M100 50.59c0 27.61-22.39 49.99-50 49.99S0 78.2 0 50.59C0 22.98 22.39.59 50 .59s50 22.39 50 50Z" fill="currentColor" opacity=".2"/>
                    <path d="M93.97 39.04c2.43-.64 3.9-3.13 3.04-5.49-1.72-4.73-4.14-9.17-7.2-13.19-3.97-5.23-8.94-9.63-14.67-12.94C69.54 4.1 63.28 1.94 56.77 1.05 51.77.37 46.7.45 41.73 1.28c-2.47.41-3.92 2.9-3.28 5.34.64 2.44 3.11 3.9 5.55 3.26 4.93-1.14 10.1-1.07 15 .19 5.38 1.39 10.33 4 14.48 7.62 3.74 3.2 6.76 7.12 8.88 11.5.99 2.03 3.32 3.05 5.61 2.35Z" fill="currentColor"/>
                </svg>
                <span class="sr-only">{{ __("general.a11y.loading") }}</span>
            </span>
        @endif

        @if($loadingText)
            <span wire:loading.remove>{{ $slot }}</span>
            <span wire:loading>{{ $loadingText }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
