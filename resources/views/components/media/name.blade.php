{{-- The archive-style name (005DISC_ALP_Certificate_Volunteers). It may wrap after each "_", never inside a word. --}}
@props(['r'])

<span {{ $attributes->merge(['class' => 'mrname']) }} dir="ltr">{!! str_replace('_', '_<wbr>', e($r->displayName())) !!}</span>
