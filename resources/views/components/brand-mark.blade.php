@props(['variant' => 'sidebar'])

@php
    use App\Support\Brand;

    $user = auth()->user();
    $isGlobalMode = (bool) $user?->is_super_admin;
    $company = $isGlobalMode ? null : $user?->getCurrentCompany();

    // Modo global: logo del SaaS / SCZ SOFT. Modo clínica: logo de la clínica.
    $logoUrl = $isGlobalMode ? Brand::globalLogoUrl() : $company?->logoUrl();
    $title = $isGlobalMode ? Str::upper(Brand::appName()) : ($company?->displayName() ?? Brand::appName());
    $subtitle = $isGlobalMode ? 'Modo Global' : Brand::appName();
@endphp

<div {{ $attributes->merge(['class' => 'brand-mark brand-mark--' . $variant]) }}>
    @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $title }}" class="brand-logo">
    @else
        <span class="brand-icon"><x-tooth-icon size="1.05rem" /></span>
    @endif

    <div class="brand-text">
        <div class="brand-title">{{ $title }}</div>
        <small class="brand-subtitle">{{ $subtitle }}</small>
    </div>
</div>
