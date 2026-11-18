@extends('layouts.app')

@section('title', 'Nueva compra')

@section('page')
@php
    $symbol = \App\Support\Money::symbol($tenantCompany);
    $catalog = $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->presentation ? "{$p->name} — {$p->presentation}" : $p->name,
        'cost' => (float) $p->purchase_price, 'track' => (bool) $p->track_batches, 'unit' => mb_strtolower($p->unit_label)])->values();
    $initial = old('items') ? collect(old('items'))->values() : $preselected->map(fn ($p) => ['product_id' => $p->id, 'quantity' => max(1, (float) $p->min_stock * 2), 'unit_cost' => (float) $p->purchase_price])->values();
@endphp
<div class="container-fluid" style="max-width: 1150px;">
    <div class="page-header">
        <div>
            <a href="{{ route('purchases.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Compras</a>
            <h1 class="mt-1">Nueva compra</h1>
        </div>
    </div>

    @include('admin.finance.partials.errors')

    @if($suppliers->isEmpty() || $products->isEmpty())
        <div class="alert alert-warning">
            <i class="bi bi-info-circle"></i> Para registrar una compra necesitas
            @if($suppliers->isEmpty()) al menos un <a href="{{ route('suppliers.create') }}">proveedor</a>@endif
            @if($suppliers->isEmpty() && $products->isEmpty()) y @endif
            @if($products->isEmpty()) al menos un <a href="{{ route('products.create') }}">producto</a>@endif.
        </div>
    @endif

    <form method="POST" action="{{ route('purchases.store') }}" id="purchase-form" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-lg-8">
                <section class="card form-section mb-3">
                    <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-truck"></i></span> Proveedor y factura</div>
                    <div class="card-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="supplier_id">Proveedor <span class="text-danger">*</span></label>
                            <select id="supplier_id" name="supplier_id" class="form-select" required>
                                <option value="">Elige…</option>
                                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((string) old('supplier_id', request('supplier')) === (string) $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="purchased_at">Fecha</label>
                            <input type="date" id="purchased_at" name="purchased_at" class="form-control" value="{{ old('purchased_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="document_number">Nº de factura</label>
                            <input type="text" id="document_number" name="document_number" class="form-control" maxlength="60" value="{{ old('document_number') }}">
                        </div>
                        @if($branches->count() > 1)
                            <div class="col-md-6">
                                <label class="form-label" for="branch_id">Se recibe en</label>
                                <select id="branch_id" name="branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) old('branch_id') === (string) $b->id)>{{ $b->name }}</option>@endforeach</select>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="card form-section mb-3">
                    <div class="card-header"><span class="section-icon" style="--tone:#059669"><i class="bi bi-box-seam"></i></span> Productos</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="lines">
                            <thead><tr><th class="ps-3" style="min-width: 14rem">Producto</th><th style="width: 7rem">Cantidad</th><th style="width: 8rem">Costo unit.</th><th style="width: 7rem">Desc.</th><th class="text-end" style="width: 7rem">Total</th><th></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="card-body border-top d-flex gap-2 align-items-center flex-wrap">
                        <select id="add-product" class="form-select" style="max-width: 26rem" aria-label="Agregar producto">
                            <option value="">+ Agregar producto…</option>
                            @foreach($catalog as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach
                        </select>
                        <a href="{{ route('products.create') }}" class="small" target="_blank">Producto nuevo</a>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <div class="card position-sticky" style="top: 1rem">
                    <div class="card-body">
                        <dl class="info-list mb-2">
                            <dt>Subtotal</dt><dd class="text-end tabular" id="t-subtotal">0.00</dd>
                            <dt>Descuento</dt>
                            <dd class="text-end"><div class="input-group input-group-sm ms-auto" style="max-width: 9rem"><span class="input-group-text">{{ $symbol }}</span><input type="number" name="discount" id="discount" step="0.01" min="0" value="{{ old('discount', 0) }}" class="form-control text-end"></div></dd>
                            <dt class="fw-bold text-dark">Total</dt><dd class="text-end tabular fs-4 fw-bold" id="t-total">0.00</dd>
                        </dl>

                        <div class="form-check form-switch my-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="receive_now" name="receive_now" value="1" @checked(old('receive_now', true))>
                            <label class="form-check-label" for="receive_now"><strong>Ya llegó la mercadería</strong><div class="small text-muted">Entra al stock ahora. Si es un pedido, desmárcalo y recíbelo al llegar.</div></label>
                        </div>

                        <hr>
                        <label class="form-label fw-semibold" for="pay_amount">¿Cuánto se paga ahora?</label>
                        <div class="d-flex gap-2 mb-2">
                            <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" id="pay_amount" name="pay_amount" class="form-control" step="0.01" min="0" value="{{ old('pay_amount', 0) }}"></div>
                            <button type="button" class="btn btn-light text-nowrap" id="pay-all">Todo</button>
                        </div>
                        <div id="credit-fields">
                            <label class="form-label small" for="due_date">El saldo se paga hasta</label>
                            <input type="date" id="due_date" name="due_date" class="form-control form-control-sm mb-2" value="{{ old('due_date', today()->addDays(30)->toDateString()) }}">
                        </div>
                        <div id="pay-fields" hidden>
                            @include('admin.finance.partials.source-picker', ['source' => $source, 'id' => 'purchase'])
                        </div>
                        <input type="text" name="notes" class="form-control form-control-sm mt-3" maxlength="1000" placeholder="Nota (opcional)" value="{{ old('notes') }}" aria-label="Nota">
                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-check2-circle"></i> Registrar compra</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@include('admin.finance.partials.styles')
@push('scripts')
<script>
    (() => {
        const catalog = {!! json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const initial = {!! json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const body = document.querySelector('#lines tbody');
        const receiveNow = document.getElementById('receive_now');
        const fmt = (v) => (Math.round(v * 100) / 100).toFixed(2);
        let seq = 0;

        const addLine = (line) => {
            const p = catalog.find((c) => String(c.id) === String(line.product_id));
            if (!p) return;
            const i = seq++;
            const tr = document.createElement('tr');
            tr.dataset.track = p.track ? '1' : '';
            tr.innerHTML = `
                <td class="ps-3"><input type="hidden" name="items[${i}][product_id]" value="${p.id}"><div class="fw-semibold small">${p.name}</div>
                    <div class="d-flex gap-1 mt-1 js-lot" ${p.track ? '' : 'hidden'}>
                        <input type="text" name="items[${i}][lot]" class="form-control form-control-sm" placeholder="Lote" maxlength="60" value="${line.lot ?? ''}">
                        <input type="date" name="items[${i}][expires_at]" class="form-control form-control-sm" title="Vence" value="${line.expires_at ?? ''}">
                    </div></td>
                <td><div class="input-group input-group-sm"><input type="number" name="items[${i}][quantity]" class="form-control js-calc" step="0.01" min="0.01" value="${line.quantity ?? 1}"></div><div class="small text-muted">${p.unit}</div></td>
                <td><input type="number" name="items[${i}][unit_cost]" class="form-control form-control-sm js-calc" step="0.01" min="0" value="${line.unit_cost ?? p.cost}"></td>
                <td><input type="number" name="items[${i}][discount]" class="form-control form-control-sm js-calc" step="0.01" min="0" value="${line.discount ?? 0}"></td>
                <td class="text-end tabular js-total">0.00</td>
                <td class="pe-2"><button type="button" class="btn btn-sm btn-light text-danger js-remove" title="Quitar"><i class="bi bi-x-lg"></i></button></td>`;
            body.appendChild(tr);
            calc();
        };

        const calc = () => {
            let subtotal = 0;
            body.querySelectorAll('tr').forEach((tr) => {
                const [q, c, d] = [...tr.querySelectorAll('.js-calc')].map((el) => parseFloat(el.value) || 0);
                const total = Math.max(q * c - d, 0);
                tr.querySelector('.js-total').textContent = fmt(total);
                tr.querySelector('.js-lot').hidden = !(tr.dataset.track && receiveNow.checked);
                subtotal += total;
            });
            const discount = Math.min(parseFloat(document.getElementById('discount').value) || 0, subtotal);
            const total = subtotal - discount;
            document.getElementById('t-subtotal').textContent = fmt(subtotal);
            document.getElementById('t-total').textContent = fmt(total);
            document.getElementById('t-total').dataset.value = total;
            const pay = parseFloat(document.getElementById('pay_amount').value) || 0;
            document.getElementById('pay-fields').hidden = pay <= 0;
            document.getElementById('credit-fields').hidden = pay >= total && total > 0;
        };

        document.getElementById('add-product').addEventListener('change', (e) => { if (e.target.value) { addLine({ product_id: e.target.value }); e.target.value = ''; } });
        body.addEventListener('input', calc);
        body.addEventListener('click', (e) => { if (e.target.closest('.js-remove')) { e.target.closest('tr').remove(); calc(); } });
        document.getElementById('purchase-form').addEventListener('input', (e) => { if (['discount', 'pay_amount'].includes(e.target.id)) calc(); });
        receiveNow.addEventListener('change', calc);
        document.getElementById('pay-all').addEventListener('click', () => { document.getElementById('pay_amount').value = fmt(parseFloat(document.getElementById('t-total').dataset.value) || 0); calc(); });
        initial.forEach(addLine);
        calc();
    })();
</script>
@endpush
@endsection
