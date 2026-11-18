@extends('layouts.app')

@section('title', 'Cobrar' . ($patient ? ' · ' . $patient->full_name : ''))

@section('page')
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $symbol = \App\Support\Money::symbol($tenantCompany);
    // Lo pendiente como «líneas» para el carrito (JS).
    $pendingLines = collect();
    foreach ($pending['consultation'] as $cp) {
        $pendingLines->push(['key' => "consultation-{$cp->id}", 'source' => 'consultation', 'id' => $cp->id, 'group' => 'consulta',
            'description' => $cp->name . ($cp->tooth ? " · pieza {$cp->tooth}" . ($cp->surfaces ? " {$cp->surfaces}" : '') : ''),
            'origin' => "{$cp->consultation->code} · " . $cp->consultation->started_at?->format('d/m/Y'),
            'quantity' => $cp->quantity, 'unit_price' => (float) $cp->price, 'locked' => true]);
    }
    foreach ($pending['plan'] as $item) {
        $pendingLines->push(['key' => "plan-{$item->id}", 'source' => 'plan', 'id' => $item->id, 'group' => 'plan',
            'description' => $item->name . ($item->tooth ? " · pieza {$item->tooth}" . ($item->surfaces ? " {$item->surfaces}" : '') : ''),
            'origin' => $item->plan->code . ' · ' . ($item->status === 'realizado' ? 'realizado' : 'por adelantado'),
            'quantity' => $item->quantity, 'unit_price' => (float) $item->unit_price, 'locked' => true, 'done' => $item->status === 'realizado']);
    }
    $grouped = $procedures->groupBy(fn ($p) => $p->specialty?->name ?? 'Otros');
    $cashMethod = $methods->first(fn ($m) => $m->isCash());
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-cart-plus"></i> Cobrar</h1>
            <p class="page-subtitle">Elige al paciente: aparece lo que tiene pendiente de cobro.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('sales.index') }}" class="btn btn-light"><i class="bi bi-receipt"></i> Ventas</a>
        </div>
    </div>

    @if($cashEnabled && $sessions->isEmpty())
        <div class="app-banner alert-warning">
            <i class="bi bi-safe"></i>
            <div>No hay ninguna caja abierta: para cobrar, abre el turno de caja.</div>
            @if(Route::has('cash.index'))<a href="{{ route('cash.index') }}" class="btn btn-sm btn-warning banner-action">Abrir caja</a>@endif
        </div>
    @endif

    <form method="POST" action="{{ route('sales.store') }}" id="sale-form" novalidate>
        @csrf
        <input type="hidden" name="patient_id" value="{{ $patient?->id }}">
        <div id="hidden-items"></div>

        <div class="row g-3">
            <div class="col-lg-7 col-xl-8">
                {{-- Paciente --}}
                <section class="card form-section">
                    <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-person"></i></span> Paciente</div>
                    <div class="card-body">
                        @if($patient)
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="avatar">{{ $patient->initials }}</span>
                                <div class="flex-fill min-w-0">
                                    <div class="fw-semibold">{{ $patient->full_name }}</div>
                                    <div class="small text-muted">{{ collect([$patient->code, $patient->document_label, $patient->phone])->filter()->implode(' · ') }}</div>
                                </div>
                                @if($debt > 0)<span class="badge bg-danger-subtle text-danger-emphasis">Debe {{ $money($debt) }}</span>@endif
                                <a href="{{ route('sales.create') }}" class="btn btn-sm btn-light">Cambiar</a>
                            </div>
                        @else
                            <div class="search-hero position-relative">
                                <i class="bi bi-search"></i>
                                <input type="search" class="form-control" id="patient-query" placeholder="Nombre, CI, teléfono o HC…" autocomplete="off" aria-label="Buscar paciente" autofocus>
                            </div>
                            <div class="list-group mt-2 shadow-sm" id="patient-results"></div>
                            <div class="form-text">Sin paciente es una venta de mostrador: se paga en el momento (no puede quedar saldo).</div>
                        @endif
                    </div>
                </section>

                {{-- Pendiente de cobro --}}
                @if($patient)
                    <section class="card form-section">
                        <div class="card-header"><span class="section-icon" style="--tone:#ea580c"><i class="bi bi-hourglass-split"></i></span> Pendiente de cobro
                            <span class="section-hint">Marca lo que se cobra ahora</span></div>
                        @if($pendingLines->isEmpty())
                            <div class="card-body small text-muted">No tiene nada pendiente de cobro. Puedes agregar algo del tarifario.</div>
                        @else
                            <ul class="list-group list-group-flush">
                                @foreach($pendingLines as $line)
                                    <li class="list-group-item">
                                        <label class="d-flex align-items-center gap-3 mb-0" style="cursor:pointer">
                                            <input class="form-check-input mt-0" type="checkbox" data-pending="{{ $line['key'] }}" @checked(in_array($line['key'], $preselected, true))>
                                            <span class="flex-fill">
                                                <span class="fw-semibold">{{ $line['description'] }}</span>
                                                <span class="d-block small text-muted">
                                                    <i class="bi {{ $line['group'] === 'consulta' ? 'bi-clipboard2-pulse' : 'bi-clipboard2-check' }}"></i> {{ $line['origin'] }}
                                                    @if($line['quantity'] > 1) · ×{{ $line['quantity'] }}@endif
                                                </span>
                                            </span>
                                            <span class="tabular fw-semibold">{{ $money($line['unit_price'] * $line['quantity']) }}</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                {{-- Agregar suelto --}}
                <section class="card form-section">
                    <div class="card-header"><span class="section-icon" style="--tone:#059669"><i class="bi bi-plus-lg"></i></span> Agregar</div>
                    <div class="card-body row g-2 align-items-end">
                        <div class="col-md-7">
                            <label class="form-label small" for="add-procedure">Del tarifario</label>
                            <select id="add-procedure" class="form-select">
                                <option value="">Elige…</option>
                                @foreach($grouped as $specialty => $list)
                                    <optgroup label="{{ $specialty }}">
                                        @foreach($list as $proc)
                                            <option value="{{ $proc->id }}" data-name="{{ $proc->name }}" data-price="{{ (float) $proc->price }}">{{ $proc->name }}{{ $proc->hasPrice() ? ' · ' . $money($proc->price) : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5 d-flex gap-2">
                            <input type="text" id="add-other" class="form-control" placeholder="Otro concepto…" maxlength="255" aria-label="Otro concepto">
                            <button type="button" class="btn btn-outline-primary text-nowrap" id="add-other-btn">Agregar</button>
                        </div>
                        @if($products->isNotEmpty())
                            <div class="col-md-7">
                                <label class="form-label small" for="add-product">Producto <span class="text-muted fw-normal">(sale del stock)</span></label>
                                <select id="add-product" class="form-select">
                                    <option value="">Elige…</option>
                                    @foreach($products as $prod)
                                        @php $inStock = $prod->stockIn(); @endphp
                                        <option value="{{ $prod->id }}" data-name="{{ $prod->label }}" data-price="{{ (float) $prod->sale_price }}" @disabled($inStock <= 0)>{{ $prod->label }} · {{ $money($prod->sale_price) }} · {{ $inStock > 0 ? 'quedan ' . \App\Support\Stock::number($inStock) : 'sin stock' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if($professionals->isNotEmpty())
                            <div class="col-12">
                                <label class="form-label small" for="sale-personal">Atendió <span class="text-muted fw-normal">(lo agregado aquí; lo de consultas y planes ya trae su odontólogo)</span></label>
                                <select name="personal_id" id="sale-personal" class="form-select form-select-sm">
                                    <option value="">— Nadie / venta de mostrador —</option>
                                    @foreach($professionals as $pro)
                                        <option value="{{ $pro->id }}" @selected((string) old('personal_id', $defaultProfessional) === (string) $pro->id)>{{ $pro->full_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Detalle --}}
                <section class="card">
                    <div class="card-header d-flex align-items-center"><i class="bi bi-receipt me-2"></i> Detalle <span class="ms-auto small text-muted fw-normal" id="cart-count"></span></div>
                    <div class="table-responsive">
                        <table class="table align-middle table-stack mb-0">
                            <thead><tr><th class="ps-3">Concepto</th><th style="width:5.5rem">Cant.</th><th class="text-end" style="width:8.5rem">Precio</th><th class="text-end" style="width:8rem">Descuento</th><th class="text-end" style="width:7rem">Total</th><th class="pe-3" style="width:1%"></th></tr></thead>
                            <tbody id="cart"></tbody>
                        </table>
                    </div>
                    <div class="card-body text-muted small" id="cart-empty">Todavía no hay nada para cobrar.</div>
                </section>
            </div>

            {{-- Pago --}}
            <div class="col-lg-5 col-xl-4">
                <div class="card" style="position: sticky; top: 76px;">
                    <div class="card-header">Pago</div>
                    <div class="card-body">
                        <dl class="info-list mb-2">
                            <dt>Subtotal</dt><dd class="text-end tabular" id="t-subtotal">—</dd>
                            <dt><label for="discount" class="m-0">Descuento</label></dt>
                            <dd class="text-end"><div class="input-group input-group-sm ms-auto" style="max-width: 9rem"><span class="input-group-text">{{ $symbol }}</span><input type="number" name="discount" id="discount" step="0.5" min="0" value="{{ old('discount', $suggestedDiscount) }}" class="form-control text-end"></div></dd>
                        </dl>
                        <div class="d-flex justify-content-between align-items-baseline border-top pt-2 mb-3">
                            <span class="fw-semibold">Total</span><span class="fs-3 fw-bold tabular" id="t-total">—</span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="form-label m-0">Cómo paga</span>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="add-payment">+ otro método</button>
                        </div>
                        <div id="payments" class="d-flex flex-column gap-2 mb-2"></div>
                        <div class="d-flex flex-wrap gap-1 mb-3" id="quick-cash"></div>

                        <div class="small d-flex justify-content-between"><span>Paga</span><span class="tabular" id="t-paid">—</span></div>
                        <div class="small d-flex justify-content-between text-success d-none" id="row-change"><span>Vuelto (efectivo)</span><span class="tabular fw-semibold" id="t-change"></span></div>
                        <div class="small d-flex justify-content-between text-danger d-none" id="row-balance"><span>Queda debiendo</span><span class="tabular fw-semibold" id="t-balance"></span></div>

                        <div id="due-wrap" class="mt-2 d-none">
                            <label class="form-label small" for="due_date">Paga el saldo hasta</label>
                            <input type="date" name="due_date" id="due_date" class="form-control form-control-sm" min="{{ now()->toDateString() }}" value="{{ old('due_date', now()->addDays(30)->toDateString()) }}">
                        </div>

                        @if($cashEnabled && $sessions->count() > 1)
                            <label class="form-label small mt-3" for="cash_session_id">Entra a la caja</label>
                            <select name="cash_session_id" id="cash_session_id" class="form-select form-select-sm">
                                @foreach($sessions as $session)
                                    <option value="{{ $session->id }}">{{ $session->caja?->name }} · desde {{ $session->opened_at->format('H:i') }}</option>
                                @endforeach
                            </select>
                        @elseif($cashEnabled && $sessions->count() === 1)
                            <input type="hidden" name="cash_session_id" value="{{ $sessions->first()->id }}">
                            <div class="small text-muted mt-2"><i class="bi bi-safe"></i> Entra a {{ $sessions->first()->caja?->name }}</div>
                        @endif

                        <input type="text" name="notes" class="form-control form-control-sm mt-3" maxlength="1000" placeholder="Nota (opcional)" value="{{ old('notes') }}" aria-label="Nota">

                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-3" id="submit-btn" disabled><i class="bi bi-check2-circle"></i> <span>Cobrar</span></button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const symbol = {!! json_encode($symbol) !!};
    const pending = {!! json_encode($pendingLines->keyBy('key'), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const methods = {!! json_encode($methods->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'cash' => $m->isCash(), 'ref' => (bool) $m->requires_reference])->values(), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const hasPatient = {!! json_encode((bool) $patient) !!};
    const fmt = (v) => `${symbol} ${Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const round = (v) => Math.round(v * 100) / 100;
    const $ = (id) => document.getElementById(id);
    let cart = [], seq = 0;
    // El monto del primer pago sigue al total hasta que la persona lo cambie a mano.
    let autoAmount = true;

    // ── Paciente (sin paciente elegido) ──
    const query = $('patient-query');
    if (query) {
        let timer;
        query.addEventListener('input', () => {
            clearTimeout(timer);
            const term = query.value.trim();
            if (term.length < 2) { $('patient-results').innerHTML = ''; return; }
            timer = setTimeout(async () => {
                const list = await fetch({!! json_encode(route('patients.search')) !!} + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => []);
                $('patient-results').innerHTML = '';
                list.forEach((p) => {
                    const a = document.createElement('a');
                    a.className = 'list-group-item list-group-item-action';
                    a.href = {!! json_encode(route('sales.create')) !!} + '?patient_id=' + p.id;
                    a.innerHTML = '<span class="fw-semibold"></span><small class="d-block text-muted"></small>';
                    a.querySelector('.fw-semibold').textContent = p.name;
                    a.querySelector('small').textContent = p.detail;
                    $('patient-results').appendChild(a);
                });
                if (!list.length) $('patient-results').innerHTML = '<div class="list-group-item small text-muted">Sin resultados.</div>';
            }, 220);
        });
    }

    // ── Carrito ──
    function addLine(line) { cart.push(Object.assign({ uid: ++seq, discount: 0 }, line)); render(); }
    function removeKey(key) { cart = cart.filter((l) => l.key !== key); render(); }

    function render() {
        const body = $('cart');
        body.innerHTML = '';
        cart.forEach((l) => {
            const tr = document.createElement('tr');
            const total = round(l.quantity * l.unit_price - (l.discount || 0));
            tr.innerHTML = `<td class="ps-3 stack-full"><span class="fw-semibold"></span><div class="small text-muted"></div></td>
                <td data-label="Cantidad">${l.locked ? `<span class="tabular">${l.quantity}</span>` : `<input type="number" min="1" step="1" class="form-control form-control-sm" data-f="quantity" value="${l.quantity}" aria-label="Cantidad">`}</td>
                <td class="text-end" data-label="Precio">${l.locked ? `<span class="tabular">${fmt(l.unit_price)}</span>` : `<input type="number" min="0" step="0.5" class="form-control form-control-sm text-end" data-f="unit_price" value="${l.unit_price}" aria-label="Precio">`}</td>
                <td class="text-end" data-label="Descuento"><input type="number" min="0" step="0.5" class="form-control form-control-sm text-end" data-f="discount" value="${l.discount || 0}" aria-label="Descuento"></td>
                <td class="text-end fw-semibold tabular" data-label="Total">${fmt(total)}</td>
                <td class="pe-3 text-end"><button type="button" class="btn btn-sm btn-light text-danger" aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>`;
            tr.querySelector('.fw-semibold').textContent = l.description;
            tr.querySelector('.small').textContent = l.origin || '';
            tr.querySelectorAll('[data-f]').forEach((input) => input.addEventListener('change', () => { l[input.dataset.f] = parseFloat(input.value) || 0; render(); }));
            tr.querySelector('button').addEventListener('click', () => {
                const box = document.querySelector(`[data-pending="${l.key}"]`);
                if (box) box.checked = false;
                cart = cart.filter((x) => x.uid !== l.uid); render();
            });
            body.appendChild(tr);
        });
        $('cart-empty').classList.toggle('d-none', cart.length > 0);
        $('cart-count').textContent = cart.length ? `${cart.length} ${cart.length === 1 ? 'ítem' : 'ítems'}` : '';
        totals();
    }

    function totals() {
        const subtotal = round(cart.reduce((s, l) => s + Math.max(0, l.quantity * l.unit_price - (l.discount || 0)), 0));
        const discount = Math.min(parseFloat($('discount').value) || 0, subtotal);
        const total = round(subtotal - discount);
        const rows = document.querySelectorAll('.pay-row');
        if (autoAmount && rows.length === 1) rows[0].querySelector('[data-amount]').value = total || '';
        const pays = [...document.querySelectorAll('.pay-row')].map((row) => ({ cash: methods.find((m) => String(m.id) === row.querySelector('select').value)?.cash, amount: parseFloat(row.querySelector('[data-amount]').value) || 0 }));
        const paid = round(pays.reduce((s, p) => s + p.amount, 0));
        const change = round(Math.max(0, paid - total));
        const balance = round(Math.max(0, total - paid));
        $('t-subtotal').textContent = fmt(subtotal);
        $('t-total').textContent = fmt(total);
        $('t-paid').textContent = fmt(Math.min(paid, total));
        $('row-change').classList.toggle('d-none', change <= 0);
        $('t-change').textContent = fmt(change);
        $('row-balance').classList.toggle('d-none', balance <= 0 || !cart.length);
        $('t-balance').textContent = fmt(balance);
        $('due-wrap').classList.toggle('d-none', balance <= 0 || !cart.length || !hasPatient);
        const button = $('submit-btn');
        button.disabled = !cart.length || (balance > 0 && !hasPatient);
        button.querySelector('span').textContent = !cart.length ? 'Cobrar' : (paid <= 0 ? `Registrar a crédito ${fmt(total)}` : (balance > 0 ? `Cobrar ${fmt(paid)} (queda ${fmt(balance)})` : `Cobrar ${fmt(total)}`));
        quickCash(total);
        return { total };
    }

    // Botones rápidos de efectivo: exacto y billetes redondos.
    function quickCash(total) {
        const box = $('quick-cash');
        box.innerHTML = '';
        if (!total) return;
        const options = [total, Math.ceil(total / 10) * 10, Math.ceil(total / 50) * 50, Math.ceil(total / 100) * 100]
            .filter((v, i, a) => a.indexOf(v) === i && v >= total).slice(0, 4);
        options.forEach((value, i) => {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary';
            b.textContent = i === 0 ? 'Exacto' : fmt(value);
            b.addEventListener('click', () => {
                const first = document.querySelector('.pay-row');
                autoAmount = false;
                first.querySelector('[data-amount]').value = value;
                document.querySelectorAll('.pay-row').forEach((r, idx) => { if (idx > 0) r.remove(); });
                totals();
            });
            box.appendChild(b);
        });
    }

    // ── Pagos ──
    let payIndex = 0;
    function addPayment(methodId, amount = '') {
        const i = payIndex++;
        const row = document.createElement('div');
        row.className = 'pay-row d-flex gap-1 flex-wrap';
        row.innerHTML = `<select name="payments[${i}][payment_method_id]" class="form-select form-select-sm" style="flex: 1 1 8rem" aria-label="Método">${methods.map((m) => `<option value="${m.id}">${m.name}</option>`).join('')}</select>
            <div class="input-group input-group-sm" style="flex: 1 1 8rem"><span class="input-group-text">${symbol}</span><input type="number" min="0" step="0.5" name="payments[${i}][amount]" class="form-control text-end" data-amount value="${amount}" aria-label="Monto"></div>
            <input type="text" name="payments[${i}][reference]" class="form-control form-control-sm d-none" maxlength="80" placeholder="Nº de comprobante" data-ref aria-label="Referencia">
            ${i > 0 ? '<button type="button" class="btn btn-sm btn-light text-danger" aria-label="Quitar"><i class="bi bi-x-lg"></i></button>' : ''}`;
        const select = row.querySelector('select');
        if (methodId) select.value = methodId;
        const toggleRef = () => row.querySelector('[data-ref]').classList.toggle('d-none', !methods.find((m) => String(m.id) === select.value)?.ref);
        select.addEventListener('change', () => { toggleRef(); totals(); });
        row.querySelector('[data-amount]').addEventListener('input', () => { autoAmount = false; totals(); });
        row.querySelector('button')?.addEventListener('click', () => { row.remove(); totals(); });
        toggleRef();
        $('payments').appendChild(row);
    }
    $('add-payment').addEventListener('click', () => { autoAmount = false; addPayment(methods.find((m) => !m.cash)?.id); totals(); });

    // ── Eventos ──
    document.querySelectorAll('[data-pending]').forEach((box) => box.addEventListener('change', () => {
        box.checked ? addLine(Object.assign({}, pending[box.dataset.pending])) : removeKey(box.dataset.pending);
    }));
    $('add-procedure').addEventListener('change', (e) => {
        const o = e.target.selectedOptions[0];
        if (!o?.value) return;
        addLine({ key: 'free-' + seq, source: 'free', procedure_id: o.value, description: o.dataset.name, origin: 'Del tarifario', quantity: 1, unit_price: parseFloat(o.dataset.price) || 0, locked: false });
        e.target.value = '';
    });
    $('add-product')?.addEventListener('change', (e) => {
        const o = e.target.selectedOptions[0];
        if (!o?.value) return;
        addLine({ key: 'product-' + seq, source: 'product', product_id: o.value, description: o.dataset.name, origin: 'Producto', quantity: 1, unit_price: parseFloat(o.dataset.price) || 0, locked: false });
        e.target.value = '';
    });
    $('add-other-btn').addEventListener('click', () => {
        const text = $('add-other').value.trim();
        if (!text) { $('add-other').focus(); return; }
        addLine({ key: 'free-' + seq, source: 'free', description: text, origin: 'Concepto libre', quantity: 1, unit_price: 0, locked: false });
        $('add-other').value = '';
    });
    $('discount').addEventListener('input', totals);

    // Al enviar: las líneas del carrito como items[i][…].
    $('sale-form').addEventListener('submit', (e) => {
        if (!cart.length) { e.preventDefault(); return; }
        const box = $('hidden-items');
        box.innerHTML = '';
        cart.forEach((l, i) => {
            const fields = { source: l.source, id: l.id, procedure_id: l.procedure_id, product_id: l.product_id, description: l.source === 'free' ? l.description : null,
                quantity: l.locked ? null : l.quantity, unit_price: l.locked ? null : l.unit_price, discount: l.discount || 0 };
            Object.entries(fields).forEach(([k, v]) => {
                if (v === null || v === undefined || v === '') return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `items[${i}][${k}]`; input.value = v;
                box.appendChild(input);
            });
        });
        $('submit-btn').disabled = true;
    });

    addPayment({!! json_encode($cashMethod?->id) !!});
    document.querySelectorAll('[data-pending]:checked').forEach((box) => cart.push(Object.assign({ uid: ++seq, discount: 0 }, pending[box.dataset.pending])));
    render();
})();
</script>
@endsection
