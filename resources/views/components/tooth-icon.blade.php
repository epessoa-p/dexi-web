{{-- El diente de Dexi (logo sin el texto). Se usa como ícono de la marca en el menú, el login y avisos. --}}
@props(['size' => '1em'])

<img {{ $attributes->merge(['class' => 'tooth-icon', 'alt' => '', 'aria-hidden' => 'true']) }}
     src="{{ asset('images/logo-mark.png') }}"
     style="width: {{ $size }}; height: {{ $size }}; object-fit: contain;">
