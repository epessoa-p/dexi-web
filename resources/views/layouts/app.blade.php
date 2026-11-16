@extends('layouts.base')

@section('content')
@php
    $authUser = auth()->user();
    $currentCompany = $authUser->getCurrentCompany();
    $activeCompanies = $authUser->activeCompanies()->get();
    $currentSubscription = $currentCompany?->subscription;
    $warningDays = (int) global_setting('saas.expiry_warning_days', 15);
    $daysLeft = $currentSubscription?->daysRemaining();
    $showExpiryWarning = $currentSubscription
        && $currentSubscription->allowsWrite()
        && $daysLeft !== null
        && $daysLeft <= $warningDays;
    $readOnly = $currentSubscription && ! $authUser->is_super_admin && $currentSubscription->inGrace();

    // Quién está conectado: su rol en la clínica (o Superadmin).
    $roleName = $authUser->is_super_admin
        ? 'Superadmin'
        : ($currentCompany ? $currentCompany->getRoleForUser($authUser)?->name : null) ?? 'Usuario';
    $initials = collect(preg_split('/[\s._@-]+/', $authUser->name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

    // Menú por secciones con color: el mismo en la barra lateral y en el celular.
    $navMenu = \App\Support\Navigation::for($authUser, $currentCompany);
@endphp

<a href="#main" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary" style="z-index:2000">Saltar al contenido</a>

<div class="app-shell d-flex">
    <aside class="app-sidebar" aria-label="Navegación">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}" class="text-decoration-none min-w-0"><x-brand-mark variant="sidebar" /></a>
        </div>

        <div class="sidebar-scroll">
            @include('layouts.partials.nav-menu', ['menu' => $navMenu, 'prefix' => 'side'])
        </div>

        <div class="sidebar-footer">
            @include('partials.signature')
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <button class="btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebarMobile" aria-controls="appSidebarMobile" aria-label="Abrir menú">
                    <i class="bi bi-list fs-5"></i>
                </button>

                <div class="topbar-context">
                    @if($authUser->is_super_admin && ! $currentCompany)
                        <span class="badge rounded-pill text-bg-dark"><i class="bi bi-globe2"></i> Modo Global</span>
                    @elseif($currentCompany)
                        <i class="bi bi-hospital d-none d-sm-inline" style="color: var(--brand-base)"></i>
                        <strong class="text-truncate">{{ $currentCompany->displayName() }}</strong>
                    @else
                        <span>Sin clínica activa</span>
                    @endif
                </div>

                {{-- El plan solo se muestra cuando hay algo que avisar (prueba, gracia, vencida…). --}}
                @if($currentSubscription && (! $currentSubscription->isCurrent() || $currentSubscription->onTrial()))
                    <a href="{{ route('subscription.show') }}" class="badge rounded-pill text-decoration-none bg-{{ $currentSubscription->statusColor() }}-subtle text-{{ $currentSubscription->statusColor() }}-emphasis border border-{{ $currentSubscription->statusColor() }}-subtle d-none d-md-inline-block">
                        {{ $currentSubscription->plan?->name }} · {{ $currentSubscription->statusLabel() }}
                    </a>
                @endif
            </div>

            <div class="topbar-right">
                @if($authUser->is_super_admin && $currentCompany)
                    <form action="{{ route('exit-company') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-globe2"></i> <span class="d-none d-md-inline">Volver al Modo Global</span></button>
                    </form>
                @endif

                @if(! $authUser->is_super_admin && $activeCompanies->count() > 1)
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-arrow-left-right"></i> <span class="d-none d-md-inline">Cambiar clínica</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach($activeCompanies as $company)
                                <li>
                                    <form action="{{ route('set-company', $company->id) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="dropdown-item d-flex justify-content-between align-items-center gap-3">
                                            <span>{{ $company->name }}</span>
                                            @if($currentCompany && $currentCompany->id === $company->id)
                                                <i class="bi bi-check-lg text-success"></i>
                                            @endif
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="dropdown">
                    <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mi cuenta">
                        <span class="user-avatar">{{ $initials ?: '?' }}</span>
                        <span class="user-chip-text">
                            <span class="user-chip-name d-block">{{ $authUser->name }}</span>
                            <span class="user-chip-role d-block">{{ $roleName }}</span>
                        </span>
                        <i class="bi bi-chevron-down small text-muted d-none d-lg-inline"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text fw-semibold">{{ $authUser->name }}</span></li>
                        <li><span class="dropdown-item-text text-muted small">{{ $authUser->email }}</span></li>
                        @if($currentCompany)
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('subscription.show') }}"><i class="bi bi-patch-check"></i> Mi suscripción</a></li>
                        @endif
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="{{ route('logout') }}" method="POST" class="m-0">
                                @csrf
                                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main id="main" class="app-content">
            @if($showExpiryWarning)
                <div class="app-banner alert-warning" role="status">
                    <i class="bi bi-clock-history"></i>
                    <div>
                        Tu suscripción <strong>{{ $currentSubscription->plan?->name }}</strong>
                        @if($daysLeft === 0)
                            vence <strong>hoy</strong>.
                        @else
                            vence en <strong>{{ $daysLeft }} {{ Str::plural('día', $daysLeft) }}</strong>
                            ({{ $currentSubscription->periodEnd()->format('d/m/Y') }}).
                        @endif
                    </div>
                    <a href="{{ route('subscription.show') }}" class="btn btn-sm btn-warning banner-action">Ver detalles</a>
                </div>
            @endif

            @if($readOnly)
                <div class="app-banner alert-danger" role="alert">
                    <i class="bi bi-lock"></i>
                    <div>
                        La suscripción venció el <strong>{{ $currentSubscription->periodEnd()?->format('d/m/Y') }}</strong>.
                        Hasta el <strong>{{ $currentSubscription->graceEndsAt()?->format('d/m/Y') }}</strong> puedes consultar la información,
                        pero no registrar cambios.
                    </div>
                    <a href="{{ route('subscription.show') }}" class="btn btn-sm btn-danger banner-action">Ver detalles</a>
                </div>
            @endif

            @if(isset($breadcrumbs))
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb small">
                        @foreach($breadcrumbs as $label => $url)
                            @if($loop->last)
                                <li class="breadcrumb-item active">{{ $label }}</li>
                            @else
                                <li class="breadcrumb-item"><a href="{{ $url }}" class="text-decoration-none">{{ $label }}</a></li>
                            @endif
                        @endforeach
                    </ol>
                </nav>
            @endif

            @yield('page')
        </main>

        <footer class="app-footer d-lg-none">
            @include('partials.signature')
        </footer>
    </div>
</div>

<div class="offcanvas offcanvas-start" tabindex="-1" id="appSidebarMobile" aria-labelledby="appSidebarMobileLabel" style="--bs-offcanvas-width: 290px;">
    <div class="offcanvas-header border-bottom">
        <div class="offcanvas-title min-w-0" id="appSidebarMobileLabel"><x-brand-mark variant="sidebar" /></div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body p-2">
        @include('layouts.partials.nav-menu', ['menu' => $navMenu, 'prefix' => 'mobile'])
    </div>
</div>

{{-- Avisos emergentes: success / error / warning / info de la sesión. --}}
@include('layouts.partials.toasts')

{{-- Menú: cada sección recuerda si el usuario la dejó abierta; la de la página actual se abre siempre. --}}
<script>
    (function () {
        const KEY = 'dexi.nav.open';
        const read = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } };
        const write = (keys) => { try { localStorage.setItem(KEY, JSON.stringify(keys)); } catch (e) {} };

        read().forEach((key) => {
            document.querySelectorAll('.nav-section[data-nav-section="' + key + '"]').forEach((section) => {
                section.classList.add('is-open');
                section.querySelector('.collapse')?.classList.add('show');
                section.querySelector('.nav-section-toggle')?.setAttribute('aria-expanded', 'true');
            });
        });

        const remember = (event, open) => {
            const section = event.target.closest('.nav-section');
            if (!section || event.target.parentElement !== section) return;
            section.classList.toggle('is-open', open);
            const key = section.dataset.navSection;
            const keys = read().filter((k) => k !== key);
            write(open ? keys.concat(key) : keys);
        };
        document.addEventListener('show.bs.collapse', (event) => remember(event, true));
        document.addEventListener('hide.bs.collapse', (event) => remember(event, false));

        document.querySelector('.app-sidebar .app-link.active')?.scrollIntoView({ block: 'nearest' });
    })();
</script>
@endsection
