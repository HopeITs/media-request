@props(['href' => null])

@php
    $baseClasses = 'py-2 px-4 text-body font-semibold text-neutral-800 bg-neutral-200 hover:bg-neutral-300
                    cursor-pointer rounded-control shadow-e1 transition duration-150 ease-in-out
                    flex justify-center items-center disabled:opacity-60 disabled:cursor-not-allowed';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $baseClasses]) }}>
        {{ $slot }}
    </a>
@else
    <button wire:loading.attr="disabled"
        {{ $attributes->merge([
            'class' => $baseClasses,
            'type'  => 'button',
        ]) }}>
        {{ $slot }}
    </button>
@endif
