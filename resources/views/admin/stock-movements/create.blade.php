@extends('layouts.app')

@section('title', 'Registrar movimiento')

@section('page')
@php
    $kinds = \App\Http\Controllers\Admin\StockMovementController::KINDS;
    $reasons = match ($kind) {
        'entrada' => \App\Models\StockMovement::MANUAL_IN_REASONS,
        'salida' => \App\Models\StockMovement::MANUAL_OUT_REASONS,
        default => [],
    };
    $productId = (string) old('product_id', $selected);
    $data = $products->mapWithKeys(fn ($p) => [$p->id => [
        'unit' => mb_strtolower($p->unit_label),
        'track' => (bool) $p->track_batches,
        'cost' => (float) $p->purchase_price,
        'stock' => $p->stocks->mapWithKeys(fn ($s) => [$s->branch_id => (float) $s->quantity]),
        'batches' => $p->batches->map(fn ($b) => ['id' => $b->id, 'branch' => $b->branch_id, 'lot' => $b->lot, 'qty' => (float) $b->quantity, 'exp' => $b->expires_at?->format('d/m/Y')])->values(),
    ]]);
@endphp
<div class="container-fluid" style="max-width: 820px;">
    <div class="page-header">
        <div>
            <a href="{{ $selected ? route('products.show', $selected) : route('stock-movements.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Volver</a>
            <h1 class="mt-1">Registrar movimiento</h1>
        </div>
    </div>

    <nav class="filter-chips mb-3" aria-label="Tipo de movimiento">
        @foreach($kinds as $key => [$label, $icon])
            <a href="{{ route('stock-movements.create', array_filter(['kind' => $key, 'product' => $selected])) }}" class="filter-chip {{ $kind === $key ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
        @endforeach
    </nav>

    @include('admin.finance.partials.errors')

    <form method="POST" action="{{ route('stock-movements.store') }}" class="card" id="movement-form">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        <div class="card-body row g-3">
            <div class="col-12">
                <label class="form-label" for="product_id">Producto <span class="text-danger">*</span></label>
                <select id="product_id" name="product_id" class="form-select" required>
                    <option value="">Elige…</option>
                    @foreach($products as $p)<option value="{{ $p->id }}" @selected($productId === (string) $p->id)>{{ $p->label }}</option>@endforeach
                </select>
                <div class="form-text" id="stock-hint"></div>
            </div>
            <div class="{{ $kind === 'traslado' ? 'col-md-6' : 'col-md-6' }}" @if($branches->count() < 2) hidden @endif>
                <label class="form-label" for="branch_id">{{ $kind === 'traslado' ? 'Desde' : 'Consultorio' }}</label>
                <select id="branch_id" name="branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) old('branch_id') === (string) $b->id)>{{ $b->name }}</option>@endforeach</select>
            </div>
            @if($kind === 'traslado')
                <div class="col-md-6">
                    <label class="form-label" for="to_branch_id">Hacia <span class="text-danger">*</span></label>
                    <select id="to_branch_id" name="to_branch_id" class="form-select" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected($loop->index === 1)>{{ $b->name }}</option>@endforeach
                    </select>
                    @if($branches->count() < 2)<div class="form-text text-danger">Necesitas al menos dos consultorios para trasladar.</div>@endif
                </div>
            @endif
            <div class="col-md-6">
                <label class="form-label" for="quantity">{{ $kind === 'ajuste' ? 'Cantidad contada' : 'Cantidad' }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" id="quantity" name="quantity" class="form-control" step="0.01" min="0" value="{{ old('quantity') }}" required>
                    <span class="input-group-text" id="unit-label">u.</span>
                </div>
                @if($kind === 'ajuste')<div class="form-text">Lo que hay físicamente: Dexi registra la diferencia.</div>@endif
            </div>
            @if($reasons)
                <div class="col-md-6">
                    <label class="form-label" for="reason">Motivo <span class="text-danger">*</span></label>
                    <select id="reason" name="reason" class="form-select" required>
                        @foreach($reasons as $r)<option value="{{ $r }}" @selected(old('reason') === $r)>{{ \App\Models\StockMovement::REASONS[$r] }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if($kind === 'entrada')
                <div class="col-md-6">
                    <label class="form-label" for="unit_cost">Costo unitario <span class="text-muted fw-normal">(opcional)</span></label>
                    <input type="number" id="unit_cost" name="unit_cost" class="form-control" step="0.01" min="0" value="{{ old('unit_cost') }}">
                </div>
                <div class="col-md-3 js-lot" hidden>
                    <label class="form-label" for="lot">Lote <span class="text-danger">*</span></label>
                    <input type="text" id="lot" name="lot" class="form-control" maxlength="60" value="{{ old('lot') }}">
                </div>
                <div class="col-md-3 js-lot" hidden>
                    <label class="form-label" for="expires_at">Vence</label>
                    <input type="date" id="expires_at" name="expires_at" class="form-control" value="{{ old('expires_at') }}">
                </div>
            @else
                <div class="col-md-6 js-lot" hidden>
                    <label class="form-label" for="product_batch_id">Lote</label>
                    <select id="product_batch_id" name="product_batch_id" class="form-select"></select>
                    @if($kind !== 'ajuste')<div class="form-text">Vacío = el que vence primero.</div>@endif
                </div>
            @endif
            <div class="col-12">
                <label class="form-label" for="notes">Nota</label>
                <input type="text" id="notes" name="notes" class="form-control" maxlength="255" value="{{ old('notes') }}" placeholder="{{ $kind === 'salida' ? 'Se cayó la caja, uso en esterilización…' : '' }}">
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('stock-movements.index') }}" class="btn btn-light">Cancelar</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Registrar {{ mb_strtolower($kinds[$kind][0]) }}</button>
        </div>
    </form>
</div>
@push('scripts')
<script>
    (() => {
        const data = {!! json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const kind = @json($kind);
        const product = document.getElementById('product_id');
        const branch = document.getElementById('branch_id');
        const batchSelect = document.getElementById('product_batch_id');
        const sync = () => {
            const d = data[product.value];
            document.getElementById('unit-label').textContent = d ? d.unit : 'u.';
            const stock = d ? (d.stock[branch.value] ?? 0) : null;
            document.getElementById('stock-hint').textContent = d ? `Existencia en este consultorio: ${stock} ${d.unit}` : '';
            document.querySelectorAll('.js-lot').forEach((el) => { el.hidden = ! (d && d.track); });
            if (batchSelect) {
                const list = d ? d.batches.filter((b) => String(b.branch) === branch.value) : [];
                batchSelect.innerHTML = (kind === 'ajuste' ? '' : '<option value="">El que vence primero</option>')
                    + list.map((b) => `<option value="${b.id}">${b.lot} · ${b.qty} ${d.unit}${b.exp ? ' · vence ' + b.exp : ''}</option>`).join('');
            }
            const cost = document.getElementById('unit_cost');
            if (cost && d && ! cost.value && d.cost) cost.placeholder = d.cost;
        };
        product.addEventListener('change', sync);
        branch.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
@endsection
