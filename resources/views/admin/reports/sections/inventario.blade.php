<div class="row g-3 mb-3">
    @if($canMoney)
        <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Valor del inventario (hoy)', 'value' => $money($data['value']), 'hint' => 'Al último costo', 'url' => route('products.index')])</div>
    @endif
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Productos activos', 'value' => $num($data['count'])])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Bajo el mínimo', 'value' => $num(count($data['low'])), 'url' => route('products.index', ['filter' => 'bajo'])])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Lotes por vencer', 'value' => $num($data['expiring']->count()), 'hint' => 'Vencidos o en 60 días', 'url' => route('products.index', ['filter' => 'vencer'])])</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        @include('admin.reports.partials.ranked', ['title' => 'Insumos gastados en procedimientos', 'sub' => 'Lo que se descontó solo al registrar lo realizado.',
            'rows' => $data['consumed'], 'format' => $canMoney ? 'money' : 'count', 'limit' => 8, 'empty' => 'Sin insumos descontados en este período.'])
    </div>
    <div class="col-lg-6">
        <div class="rpt-card">
            <div class="rpt-head"><h3 class="rpt-card-title">Movimientos del período</h3></div>
            @if(collect($data['movements'])->isEmpty())
                @include('admin.reports.partials.empty', ['text' => 'Sin movimientos en este período.'])
            @else
                <table class="table table-sm rpt-table mb-0">
                    <thead><tr><th>Motivo</th><th class="text-end">Entradas</th><th class="text-end">Salidas</th></tr></thead>
                    <tbody>
                        @foreach($data['movements'] as $m)
                            <tr><td>{{ $m['label'] }}</td><td class="text-end text-success">{{ $m['in'] ? '+' . $num($m['in']) : '—' }}</td><td class="text-end text-danger">{{ $m['out'] ? '−' . $num($m['out']) : '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    @if($canMoney)
        <div class="col-lg-4">
            @include('admin.reports.partials.ranked', ['title' => 'Valor por categoría (hoy)', 'rows' => $data['byCategory'], 'showShare' => true, 'empty' => 'Sin existencia valorizada.'])
        </div>
    @endif
    <div class="{{ $canMoney ? 'col-lg-4' : 'col-lg-6' }}">
        <div class="rpt-card">
            <div class="rpt-head"><h3 class="rpt-card-title">Bajo el mínimo (hoy)</h3></div>
            @forelse($data['low'] as $row)
                <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $row['label'] }}</span><span class="text-danger fw-semibold">{{ $num($row['stock']) }} / {{ $num($row['min']) }} {{ $row['unit'] }}</span></div>
            @empty
                @include('admin.reports.partials.empty', ['icon' => 'bi-check2-circle', 'text' => 'Todo por encima del mínimo.'])
            @endforelse
        </div>
    </div>
    <div class="{{ $canMoney ? 'col-lg-4' : 'col-lg-6' }}">
        <div class="rpt-card">
            <div class="rpt-head"><h3 class="rpt-card-title">Vencimientos (hoy)</h3></div>
            @forelse($data['expiring'] as $b)
                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span>{{ $b->product?->name }} <span class="text-muted">· lote {{ $b->lot }}</span></span>
                    <span class="{{ $b->isExpired() ? 'text-danger' : 'text-warning-emphasis' }} fw-semibold">{{ $b->expires_at?->format('d/m/Y') }}</span>
                </div>
            @empty
                @include('admin.reports.partials.empty', ['icon' => 'bi-check2-circle', 'text' => 'Nada por vencer.'])
            @endforelse
        </div>
    </div>
</div>
