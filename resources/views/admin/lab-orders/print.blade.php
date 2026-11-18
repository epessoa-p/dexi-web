@php
    $o = $order;
    $companyId = $o->company_id;
    $clinic = $tenantCompany?->displayName();
    $address = settings()->get('company.address', null, $companyId);
    $phone = settings()->get('company.phone', null, $companyId);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de trabajo {{ $o->code }}</title>
    @include('layouts.partials.favicons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: {{ config('brand.colors.base') }}; }
        * { box-sizing: border-box; }
        body { font-family: Inter, 'Segoe UI', sans-serif; color: #1d2b30; margin: 0; background: #eef2f3; font-size: 13px; }
        .sheet { width: 210mm; min-height: 148mm; margin: 16px auto; background: #fff; padding: 14mm 16mm; box-shadow: 0 6px 24px rgba(0,0,0,.12); }
        header { display: flex; justify-content: space-between; border-bottom: 3px solid var(--brand); padding-bottom: 8px; margin-bottom: 14px; }
        .clinic { font-weight: 700; font-size: 18px; }
        .muted { color: #66777d; font-size: 11.5px; }
        h1 { font-size: 20px; margin: 0; text-align: right; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; margin-bottom: 14px; }
        .field label { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: #66777d; }
        .field div { font-size: 14px; font-weight: 600; border-bottom: 1px solid #d7e0e2; padding: 3px 0 5px; min-height: 26px; }
        .box { border: 1px solid #d7e0e2; border-radius: 8px; padding: 10px 12px; min-height: 90px; white-space: pre-wrap; }
        .shade { display: inline-block; border: 2px solid var(--brand); border-radius: 6px; padding: 2px 10px; font-size: 18px; }
        .sign { display: flex; justify-content: space-between; margin-top: 50px; }
        .sign div { width: 45%; border-top: 1px solid #1d2b30; text-align: center; padding-top: 4px; font-size: 11.5px; }
        .actions { text-align: center; margin: 12px; }
        .actions button { font: 600 14px Inter, sans-serif; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; width: auto; } .actions { display: none; } @page { size: A5 landscape; margin: 8mm; } }
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
            <div>
                <h1>ORDEN DE TRABAJO</h1>
                <div class="muted" style="text-align:right">{{ $o->code }} · {{ $o->sent_at->format('d/m/Y') }}</div>
            </div>
        </header>
        <div class="grid">
            <div class="field"><label>Laboratorio</label><div>{{ $o->lab?->name }}</div></div>
            <div class="field"><label>Odontólogo</label><div>{{ $o->professional?->full_name }}</div></div>
            <div class="field"><label>Paciente</label><div>{{ $o->patient?->full_name }}@if($o->patient?->age_label) <span class="muted">({{ $o->patient->age_label }})</span>@endif</div></div>
            <div class="field"><label>Entregar el</label><div>{{ $o->due_at?->format('d/m/Y') }}</div></div>
            <div class="field"><label>Trabajo</label><div>{{ $o->work_label }}</div></div>
            <div class="field"><label>Pieza(s)</label><div>{{ $o->teeth }}</div></div>
            <div class="field"><label>Material</label><div>{{ $o->material }}</div></div>
            <div class="field"><label>Color</label><div>@if($o->shade)<span class="shade">{{ $o->shade }}</span>@endif</div></div>
        </div>
        <div class="field"><label>Indicaciones</label></div>
        <div class="box">{{ $o->instructions }}</div>
        <div class="sign">
            <div>Firma del odontólogo</div>
            <div>Recibido por el laboratorio</div>
        </div>
    </div>
</body>
</html>
