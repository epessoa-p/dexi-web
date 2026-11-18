@php
    $s = $sale;
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $companyId = $s->company_id;
    $clinic = settings()->get('company.trade_name', null, $companyId) ?: $tenantCompany?->displayName();
    $address = settings()->get('company.address', null, $companyId);
    $phone = settings()->get('company.phone', null, $companyId);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo {{ $s->code }}</title>
    @include('layouts.partials.favicons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: {{ config('brand.colors.base') }}; }
        * { box-sizing: border-box; }
        body { font-family: Inter, 'Segoe UI', sans-serif; color: #1d2b30; margin: 0; background: #eef2f3; font-size: 12px; }
        .ticket { width: 80mm; margin: 16px auto; background: #fff; padding: 6mm 5mm; box-shadow: 0 6px 24px rgba(0,0,0,.12); }
        .center { text-align: center; }
        .clinic { font-weight: 700; font-size: 15px; }
        .muted { color: #66777d; font-size: 10.5px; }
        hr { border: 0; border-top: 1px dashed #9fb0b5; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .total td { font-size: 15px; font-weight: 700; padding-top: 4px; }
        .stamp { border: 2px solid #b02a37; color: #b02a37; font-weight: 700; text-align: center; padding: 4px; margin: 6px 0; transform: rotate(-3deg); }
        .actions { text-align: center; margin: 12px; }
        .actions button { font: 600 14px Inter, sans-serif; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; cursor: pointer; }
        @media print { body { background: #fff; } .ticket { margin: 0; box-shadow: none; } .actions { display: none; } @page { size: 80mm auto; margin: 3mm; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Imprimir</button></div>
    <div class="ticket">
        <div class="center">
            <div class="clinic">{{ $clinic }}</div>
            <div class="muted">{{ collect([$address, $phone])->filter()->implode(' · ') }}</div>
            <div style="margin-top:6px"><strong>RECIBO {{ $s->code }}</strong></div>
            <div class="muted">{{ $s->created_at->format('d/m/Y H:i') }}</div>
        </div>
        @if($s->isCancelled())<div class="stamp">ANULADO</div>@endif
        <hr>
        @if($s->patient)<div><strong>Paciente:</strong> {{ $s->patient->full_name }}</div>@if($s->patient->document_label)<div class="muted">{{ $s->patient->document_label }}</div>@endif<hr>@endif
        <table>
            @foreach($s->items as $item)
                <tr><td colspan="2">{{ $item->description }}</td></tr>
                <tr><td class="muted">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} × {{ $money($item->unit_price) }}{{ (float) $item->discount ? ' − ' . $money($item->discount) : '' }}</td><td class="num">{{ $money($item->total) }}</td></tr>
            @endforeach
        </table>
        <hr>
        <table>
            @if((float) $s->discount)<tr><td>Descuento</td><td class="num">− {{ $money($s->discount) }}</td></tr>@endif
            <tr class="total"><td>TOTAL</td><td class="num">{{ $money($s->total) }}</td></tr>
            @foreach($s->payments as $payment)
                <tr><td class="muted">{{ $payment->method?->name }}{{ $payment->kind === 'abono' ? ' (abono ' . $payment->paid_at?->format('d/m') . ')' : '' }}</td><td class="num">{{ $money($payment->amount) }}</td></tr>
            @endforeach
            @if((float) $s->balance > 0)<tr><td><strong>Saldo pendiente</strong></td><td class="num"><strong>{{ $money($s->balance) }}</strong></td></tr>@endif
        </table>
        <hr>
        <div class="center muted">@if($s->user)Atendió: {{ $s->user->name }}<br>@endif¡Gracias por su visita!</div>
    </div>
</body>
</html>
