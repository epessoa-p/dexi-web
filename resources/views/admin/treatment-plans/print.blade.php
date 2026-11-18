@php
    $p = $plan->patient;
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $companyId = $plan->company_id;
    $clinic = settings()->get('company.trade_name', null, $companyId) ?: $tenantCompany?->displayName();
    $address = settings()->get('company.address', null, $companyId);
    $phone = settings()->get('company.phone', null, $companyId);
    $active = $plan->items->where('status', '!=', 'cancelado')->groupBy('phase');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presupuesto {{ $plan->code }} · {{ $p->full_name }}</title>
    @include('layouts.partials.favicons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: {{ config('brand.colors.base') }}; }
        * { box-sizing: border-box; }
        body { font-family: Inter, 'Segoe UI', sans-serif; color: #1d2b30; margin: 0; background: #eef2f3; font-size: 12.5px; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 16mm 15mm; box-shadow: 0 6px 24px rgba(0,0,0,.12); }
        header { display: flex; justify-content: space-between; gap: 12px; border-bottom: 3px solid var(--brand); padding-bottom: 10px; margin-bottom: 14px; }
        .clinic { font-weight: 700; font-size: 18px; }
        .muted { color: #66777d; font-size: 11px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #66777d; border-bottom: 1px solid #d4dfe2; padding: 6px 4px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eef3f4; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .phase { font-weight: 700; color: var(--brand); margin: 12px 0 4px; }
        .totals { margin-left: auto; width: 60%; }
        .totals td { border: 0; padding: 3px 4px; }
        .totals .grand td { font-size: 16px; font-weight: 700; border-top: 2px solid #1d2b30; padding-top: 6px; }
        .notes { white-space: pre-line; background: #f6f9f9; border-radius: 6px; padding: 8px 10px; margin-top: 12px; }
        .signs { display: flex; gap: 30px; margin-top: 50px; }
        .signs div { flex: 1; text-align: center; border-top: 1px solid #1d2b30; padding-top: 4px; }
        .actions { text-align: center; margin: 12px; }
        .actions button { font: 600 14px Inter, sans-serif; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; } .actions { display: none; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Imprimir / guardar como PDF</button></div>
    <div class="sheet">
        <header>
            <div>
                <div class="clinic">{{ $clinic }}</div>
                <div class="muted">{{ collect([$address, $phone])->filter()->implode(' · ') }}</div>
            </div>
            <div class="muted" style="text-align:right">Presupuesto {{ $plan->code }}<br>{{ ($plan->presented_at ?? $plan->created_at)->format('d/m/Y') }}</div>
        </header>

        <h1>{{ $plan->title }}</h1>
        <div class="meta">
            <div><strong>Paciente:</strong> {{ $p->full_name }} ({{ $p->code }})</div>
            <div><strong>Profesional:</strong> {{ $plan->professional?->full_name ?? '—' }}</div>
            @if($p->document_label)<div><strong>Documento:</strong> {{ $p->document_label }}</div>@endif
            @if($plan->valid_until)<div><strong>Válido hasta:</strong> {{ $plan->valid_until->format('d/m/Y') }}</div>@endif
        </div>

        @foreach($active as $phase => $items)
            <div class="phase">Fase {{ $phase }}</div>
            <table>
                <thead><tr><th style="width:16%">Pieza</th><th>Procedimiento</th><th class="num" style="width:8%">Cant.</th><th class="num" style="width:16%">Precio</th><th class="num" style="width:16%">Subtotal</th></tr></thead>
                <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td>{{ $item->tooth ? $item->tooth . ($item->surfaces ? ' (' . $item->surfaces . ')' : '') : '—' }}</td>
                            <td>{{ $item->name }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ $money($item->unit_price) }}</td>
                            <td class="num">{{ $money($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        <table class="totals">
            <tr><td>Subtotal</td><td class="num">{{ $money($totals['subtotal']) }}</td></tr>
            @if($totals['discount'] > 0)<tr><td>Descuento</td><td class="num">− {{ $money($totals['discount']) }}</td></tr>@endif
            <tr class="grand"><td>Total</td><td class="num">{{ $money($totals['total']) }}</td></tr>
        </table>

        @if($plan->notes)<div class="notes">{{ $plan->notes }}</div>@endif

        <div class="signs">
            <div>{{ $plan->professional?->full_name ?? 'Profesional' }}</div>
            <div>{{ $plan->approved_by_name ?: ($p->isMinor() ? 'Tutor: ' . ($p->guardian_name ?? '') : $p->full_name) }}<br><span class="muted">Acepto el plan y el presupuesto</span></div>
        </div>
    </div>
</body>
</html>
