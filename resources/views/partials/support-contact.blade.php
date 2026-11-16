@php
    use App\Support\Brand;

    $supportEmail = Brand::supportEmail();
    $supportPhone = Brand::supportPhone();
@endphp

@if($supportEmail || $supportPhone)
    <hr>
    <div class="text-muted small">
        <div class="mb-1">¿Necesitas ayuda?</div>
        @if($supportEmail)
            <div><i class="bi bi-envelope"></i> <a href="mailto:{{ $supportEmail }}" class="text-decoration-none">{{ $supportEmail }}</a></div>
        @endif
        @if($supportPhone)
            <div><i class="bi bi-telephone"></i> {{ $supportPhone }}</div>
        @endif
    </div>
@endif
