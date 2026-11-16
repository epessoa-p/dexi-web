@php
    use App\Support\Brand;

    $vendorLogo = Brand::vendorLogoUrl();
    $vendorName = Brand::vendorName();
    $vendorUrl = Brand::vendorUrl();
@endphp

<div class="app-signature {{ $class ?? '' }}">
    <span class="app-signature-label">Desarrollado por</span>

    @if($vendorUrl)
        <a href="{{ $vendorUrl }}" target="_blank" rel="noopener" class="app-signature-vendor">
    @else
        <span class="app-signature-vendor">
    @endif

    @if($vendorLogo)
        <img src="{{ $vendorLogo }}" alt="{{ $vendorName }}" class="app-signature-logo">
    @else
        <strong>{{ $vendorName }}</strong>
    @endif

    @if($vendorUrl)
        </a>
    @else
        </span>
    @endif
</div>
