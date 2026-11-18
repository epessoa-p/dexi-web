@php $d = $data['deltas']; @endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-4">@include('admin.reports.partials.tile', ['label' => 'Total vendido', 'value' => $money($data['total']), 'delta' => $d['total'], 'hint' => 'Cobrado o a crédito, sin anuladas', 'url' => route('sales.index', ['filter' => 'todas'])])</div>
    <div class="col-6 col-xl-4">@include('admin.reports.partials.tile', ['label' => 'Cantidad de ventas', 'value' => $num($data['count']), 'delta' => $d['count']])</div>
    <div class="col-12 col-xl-4">@include('admin.reports.partials.tile', ['label' => 'Ticket promedio', 'value' => $money($data['ticket']), 'delta' => $d['ticket'], 'hint' => 'Lo que deja cada cobro, en promedio'])</div>
</div>

<div class="mb-3">
    @include('admin.reports.partials.chart', ['title' => 'Ventas por período', 'sub' => 'Incluye lo vendido a crédito, aunque todavía no se haya cobrado.', 'chart' => $data['chart'], 'empty' => 'Sin ventas en este período.'])
</div>

<div class="mb-3">
    @include('admin.reports.partials.segments', ['title' => '¿De dónde vienen las ventas?', 'sub' => 'Lo atendido en consulta, los planes de tratamiento y los productos.', 'parts' => $data['byType'], 'empty' => 'Sin ventas en este período.'])
</div>

<h2 class="rpt-heading">Lo que más se vende</h2>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Tratamientos', 'rows' => $data['procedures'], 'limit' => 8, 'quantityUnit' => 'veces', 'empty' => 'Sin tratamientos cobrados en este período.'])
    </div>
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Productos', 'rows' => $data['products'], 'limit' => 8, 'empty' => 'Sin productos vendidos en este período.'])
    </div>
</div>

<h2 class="rpt-heading">Cómo se reparte</h2>
<div class="row g-3">
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Por especialidad', 'rows' => $data['specialties'], 'showShare' => true, 'empty' => 'Sin tratamientos cobrados en este período.'])
    </div>
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Por usuario', 'sub' => 'Quién registró el cobro.', 'rows' => $data['users'], 'showShare' => true, 'empty' => 'Sin ventas en este período.'])
    </div>
</div>
