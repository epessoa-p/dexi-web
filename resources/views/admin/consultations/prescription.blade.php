@php
    $c = $consultation;
    $p = $c->patient;
    $companyId = $c->company_id;
    $clinic = settings()->get('company.trade_name', null, $companyId) ?: $tenantCompany?->displayName();
    $address = settings()->get('company.address', null, $companyId);
    $phone = settings()->get('company.phone', null, $companyId);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receta · {{ $p->full_name }}</title>
    @include('layouts.partials.favicons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: {{ config('brand.colors.base') }}; }
        * { box-sizing: border-box; }
        body { font-family: Inter, 'Segoe UI', sans-serif; color: #1d2b30; margin: 0; background: #eef2f3; }
        .sheet { width: 148mm; min-height: 210mm; margin: 16px auto; background: #fff; padding: 14mm 12mm; box-shadow: 0 6px 24px rgba(0,0,0,.12); display: flex; flex-direction: column; }
        header { display: flex; justify-content: space-between; gap: 12px; border-bottom: 3px solid var(--brand); padding-bottom: 10px; }
        .clinic { font-weight: 700; font-size: 17px; }
        .muted { color: #66777d; font-size: 11px; }
        .rx { font: 700 34px Georgia, serif; color: var(--brand); margin: 14px 0 4px; }
        .patient { display: grid; grid-template-columns: 1fr auto; gap: 2px 12px; font-size: 12px; margin-top: 10px; }
        .body { white-space: pre-line; font-size: 13px; line-height: 1.6; flex: 1; margin-top: 8px; }
        .next { font-size: 12px; margin-top: 12px; padding: 8px 10px; background: #f3f8f8; border-radius: 6px; }
        .sign { margin-top: 40px; text-align: center; font-size: 12px; }
        .sign .line { border-top: 1px solid #1d2b30; width: 60%; margin: 0 auto 4px; }
        .actions { text-align: center; margin: 12px; }
        .actions button { font: 600 14px Inter, sans-serif; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; } .actions { display: none; } @page { size: A5; margin: 10mm; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Imprimir</button></div>
    <div class="sheet">
        <header>
            <div>
                <div class="clinic">{{ $clinic }}</div>
                <div class="muted">{{ collect([$address, $phone])->filter()->implode(' · ') }}</div>
            </div>
            <div class="muted" style="text-align:right">{{ $c->code }}<br>{{ ($c->closed_at ?? $c->started_at)?->format('d/m/Y') }}</div>
        </header>

        <div class="patient">
            <div><strong>Paciente:</strong> {{ $p->full_name }}</div>
            <div>{{ $p->age_label }}</div>
            @if($p->document_label)<div><strong>Documento:</strong> {{ $p->document_label }}</div><div></div>@endif
            @if($p->allergies)<div style="color:#b02a37"><strong>Alergias:</strong> {{ $p->allergies }}</div><div></div>@endif
        </div>

        <div class="rx">℞</div>
        <div class="body">{{ $c->indications ?: 'Sin indicaciones registradas.' }}</div>

        @if($c->next_visit)<div class="next"><strong>Próxima visita:</strong> {{ $c->next_visit }}</div>@endif

        <div class="sign">
            <div class="line"></div>
            {{ $c->professional?->full_name }}@if($c->professional?->specialty)<br><span class="muted">{{ $c->professional->specialty->name }}</span>@endif
        </div>
    </div>
</body>
</html>
