@php
    $t = $data['totals'];
    $d = $data['deltas'];
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Cobrado', 'value' => $money($t['income']), 'delta' => $d['income'], 'hint' => 'Pagos y abonos de pacientes'])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Gastos', 'value' => $money($t['expenses']), 'delta' => $d['expenses'], 'hint' => 'Registrados en Finanzas', 'url' => route('expenses.index', ['tab' => 'todos'])])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Productos e insumos', 'value' => $money($t['cost']), 'delta' => $d['cost'],
        'hint' => 'Insumos ' . $money($t['supplies']) . ' · productos ' . $money($t['products'])])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Utilidad estimada', 'value' => $money($t['profit']), 'delta' => $d['profit'], 'hint' => 'Cobrado − costo − gastos'])</div>
</div>

<div class="mb-3">
    @include('admin.reports.partials.chart', ['title' => 'Ingresos y gastos', 'sub' => 'Lo cobrado a pacientes frente a lo gastado.', 'chart' => $data['chart']])
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        @include('admin.reports.partials.segments', ['title' => '¿Cómo pagan los pacientes?', 'parts' => $data['byMethod'], 'empty' => 'Sin cobros en este período.'])
    </div>
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Gastos por categoría', 'rows' => $data['expensesByCategory'], 'showShare' => true, 'empty' => 'Sin gastos en este período.'])
    </div>
</div>

<h2 class="rpt-heading">Deudas (hoy)</h2>
<div class="row g-3">
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Te deben: ' . $money($data['receivable']), 'sub' => $data['receivableOverdue'] > 0 ? $money($data['receivableOverdue']) . ' ya vencido' : 'Nada vencido',
            'rows' => $data['debtors'], 'empty' => 'Ningún paciente debe.', 'url' => route('receivables.index')])
    </div>
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Debes: ' . $money($data['payable']), 'sub' => 'Compras a crédito y trabajos de laboratorio sin pagar.',
            'rows' => $data['creditors'], 'empty' => 'No se le debe a ningún proveedor.'])
    </div>
</div>
