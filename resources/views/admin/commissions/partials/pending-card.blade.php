{{--
    «Comisiones por pagar»: lo que se debe a cada odontólogo, de CUALQUIER fecha
    (no depende del filtro del período). Va en Comisiones y en Gastos › Personal.
--}}
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $canPay = auth()->user()->hasPermissionInCompany('commissions.create', $tenantCompany);
    $totalPending = $pending->sum('total');
@endphp
<div class="card mb-3 pending-commissions">
    <div class="card-header d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-hourglass-split text-warning"></i> Comisiones por pagar
        <span class="ms-auto fw-bold tabular {{ $totalPending > 0 ? 'text-warning-emphasis' : 'text-muted' }}">{{ $money($totalPending) }}</span>
        <span class="small text-muted">de cualquier fecha</span>
    </div>
    @if($pending->isEmpty())
        <div class="card-body small text-muted"><i class="bi bi-check2-circle text-success"></i> No se le debe nada a nadie.</div>
    @else
        <ul class="list-group list-group-flush">
            @foreach($pending as $row)
                @php $p = $row['professional']; @endphp
                <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                    <span class="avatar" style="--tone: {{ $p?->color ?? 'var(--brand-base)' }}">{{ $p ? mb_strtoupper(mb_substr($p->full_name, 0, 1)) : '?' }}</span>
                    <div class="flex-fill" style="min-width: 10rem;">
                        <a href="{{ route('commissions.professional', $p) }}" class="fw-semibold text-decoration-none text-reset">{{ $p?->full_name }}</a>
                        <div class="small text-muted">{{ $row['count'] }} {{ $row['count'] === 1 ? 'comisión' : 'comisiones' }} · desde el {{ \Carbon\Carbon::parse($row['since'])->format('d/m/Y') }}</div>
                    </div>
                    <span class="fw-bold tabular">{{ $money($row['total']) }}</span>
                    @if($canPay && $row['total'] > 0)
                        <a href="{{ route('commissions.professional', [$p, 'pay' => 1]) }}#pagar" class="btn btn-sm btn-success"><i class="bi bi-cash-coin"></i> Pagar</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
