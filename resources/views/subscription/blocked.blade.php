@extends('layouts.base')

@section('title', 'Suscripción vencida')

@section('content')
<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 20px;">
    <div class="card border-0 shadow-sm" style="max-width: 560px; width: 100%;">
        <div class="card-body p-5 text-center">
            <div class="mb-3" style="color: var(--brand-base);">
                <x-tooth-icon size="3rem" />
            </div>

            <h4 class="fw-bold mb-2">Tu suscripción no está vigente</h4>

            @if($subscription)
                <p class="text-muted mb-4">
                    El plan <strong>{{ $subscription->plan?->name }}</strong> de
                    <strong>{{ $company?->displayName() }}</strong> figura como
                    <strong>{{ Str::lower($subscription->statusLabel()) }}</strong>@if($subscription->periodEnd() && ! $subscription->isBlocked())
                        y venció el {{ $subscription->periodEnd()->format('d/m/Y') }}@endif.
                    Renuévalo para volver a usar {{ \App\Support\Brand::appName() }}.
                </p>
            @else
                <p class="text-muted mb-4">
                    {{ $company?->displayName() ?? 'Tu clínica' }} todavía no tiene un plan asignado.
                    Contacta a soporte para activarlo.
                </p>
            @endif

            <div class="text-start d-inline-block">
                @include('partials.support-contact')
            </div>

            <div class="d-flex gap-2 justify-content-center mt-4">
                <a href="{{ route('subscription.show') }}" class="btn btn-primary"><i class="bi bi-patch-check"></i> Ver mi suscripción</a>
                @if($hasOtherCompanies)
                    <a href="{{ route('select-company') }}" class="btn btn-outline-primary"><i class="bi bi-hospital"></i> Cambiar de clínica</a>
                @endif
                <form action="{{ route('logout') }}" method="POST" class="m-0">
                    @csrf
                    <button class="btn btn-light border" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
                </form>
            </div>

            <div class="mt-4">
                @include('partials.signature')
            </div>
        </div>
    </div>
</div>
@endsection
