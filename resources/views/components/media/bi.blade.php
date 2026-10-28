{{-- Arabic and English together: the page language first, the other one quieter beside or under it. --}}
@props(['k', 'params' => [], 'col' => false])

@php
    $primary = app()->getLocale() === 'ar' ? 'ar' : 'en';
    $secondary = $primary === 'ar' ? 'en' : 'ar';
    $first = __($k, $params, $primary);
    $second = __($k, $params, $secondary);
@endphp

<span {{ $attributes->merge(['class' => 'bi'.($col ? ' bi-v' : '')]) }}><span>{{ $first }}</span>@if ($second !== $first && $second !== $k)<span class="bi2" lang="{{ $secondary }}" dir="{{ $secondary === 'ar' ? 'rtl' : 'ltr' }}">{{ $second }}</span>@endif</span>
