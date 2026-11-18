{{-- Alta y edición de un producto. Parámetros: $product (null = nuevo), $categories, $branches. --}}
@php
    $p = $product;
    $isNew = $p === null;
    $val = fn (string $field, $default = null) => old($field, $p?->{$field} ?? $default);
    $err = fn (string $field) => $errors->has($field) ? 'is-invalid' : '';
    $symbol = \App\Support\Money::symbol($tenantCompany);
@endphp
<div class="container-fluid" style="max-width: 960px;">
    <div class="page-header">
        <div>
            <a href="{{ $isNew ? route('products.index') : route('products.show', $p) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $isNew ? 'Productos' : $p->name }}</a>
            <h1 class="mt-1">{{ $isNew ? 'Nuevo producto' : 'Editar producto' }}</h1>
            <p class="page-subtitle">Solo el nombre y la unidad son obligatorios.</p>
        </div>
    </div>

    @include('admin.finance.partials.errors')

    <form method="POST" action="{{ $isNew ? route('products.store') : route('products.update', $p) }}" novalidate>
        @csrf
        @unless($isNew) @method('PUT') @endunless

        <section class="card form-section mb-3">
            <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-box-seam"></i></span> Producto</div>
            <div class="card-body row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="name">Nombre <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" class="form-control {{ $err('name') }}" value="{{ $val('name') }}" maxlength="160" required placeholder="Lidocaína 2% con epinefrina" autofocus>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="product_category_id">Categoría</label>
                    <select id="product_category_id" name="product_category_id" class="form-select">
                        <option value="">Sin categoría</option>
                        @foreach($categories as $cat)<option value="{{ $cat->id }}" @selected((string) $val('product_category_id') === (string) $cat->id)>{{ $cat->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="brand">Marca</label>
                    <input type="text" id="brand" name="brand" class="form-control" value="{{ $val('brand') }}" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="presentation">Presentación</label>
                    <input type="text" id="presentation" name="presentation" class="form-control" value="{{ $val('presentation') }}" maxlength="120" placeholder="Caja x 50 cartuchos">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="unit">Se cuenta por <span class="text-danger">*</span></label>
                    <select id="unit" name="unit" class="form-select {{ $err('unit') }}">
                        @foreach(\App\Models\Product::UNITS as $key => $label)<option value="{{ $key }}" @selected($val('unit', 'unidad') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="code">Código interno</label>
                    <input type="text" id="code" name="code" class="form-control {{ $err('code') }}" value="{{ $val('code') }}" maxlength="40">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="barcode">Código de barras</label>
                    <input type="text" id="barcode" name="barcode" class="form-control" value="{{ $val('barcode') }}" maxlength="60">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="track_batches" name="track_batches" value="1" @checked($val('track_batches'))>
                        <label class="form-check-label" for="track_batches">Controlar lote y vencimiento</label>
                    </div>
                </div>
            </div>
        </section>

        <section class="card form-section mb-3">
            <div class="card-header"><span class="section-icon" style="--tone:#ca8a04"><i class="bi bi-tag"></i></span> Precios y stock</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="purchase_price">Costo</label>
                    <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" id="purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" value="{{ $val('purchase_price') }}"></div>
                    <div class="form-text">Se actualiza con cada compra.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="min_stock">Stock mínimo</label>
                    <input type="number" id="min_stock" name="min_stock" class="form-control" step="0.01" min="0" value="{{ $val('min_stock') }}">
                    <div class="form-text">Avisa cuando la existencia llegue a este número.</div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="for_sale" name="for_sale" value="1" @checked($val('for_sale')) data-toggle-target="#sale-price">
                        <label class="form-check-label" for="for_sale">Se vende en Cobrar</label>
                    </div>
                    <div id="sale-price" @unless($val('for_sale')) hidden @endunless>
                        <label class="form-label small" for="sale_price">Precio de venta</label>
                        <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" id="sale_price" name="sale_price" class="form-control" step="0.01" min="0" value="{{ $val('sale_price') }}"></div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notas</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2" maxlength="1000">{{ $val('notes') }}</textarea>
                </div>
                @unless($isNew)
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked($val('active', true))>
                            <label class="form-check-label" for="active">Activo</label>
                        </div>
                    </div>
                @endunless
            </div>
        </section>

        @if($isNew)
            <section class="card form-section mb-3">
                <div class="card-header"><span class="section-icon" style="--tone:#059669"><i class="bi bi-box-arrow-in-down"></i></span> Existencia inicial <span class="small text-muted fw-normal ms-1">(opcional)</span></div>
                <div class="card-body row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="initial_quantity">Cantidad</label>
                        <input type="number" id="initial_quantity" name="initial_quantity" class="form-control" step="0.01" min="0" value="{{ old('initial_quantity') }}">
                    </div>
                    @if($branches->count() > 1)
                        <div class="col-md-3">
                            <label class="form-label" for="initial_branch_id">Consultorio</label>
                            <select id="initial_branch_id" name="initial_branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) old('initial_branch_id') === (string) $b->id)>{{ $b->name }}</option>@endforeach</select>
                        </div>
                    @endif
                    <div class="col-md-3">
                        <label class="form-label" for="initial_lot">Lote</label>
                        <input type="text" id="initial_lot" name="initial_lot" class="form-control {{ $err('lot') }}" value="{{ old('initial_lot') }}" maxlength="60">
                        @error('lot')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="initial_expires_at">Vence</label>
                        <input type="date" id="initial_expires_at" name="initial_expires_at" class="form-control" value="{{ old('initial_expires_at') }}">
                    </div>
                </div>
            </section>
        @endif

        <div class="sticky-actions">
            <a href="{{ $isNew ? route('products.index') : route('products.show', $p) }}" class="btn btn-light">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ $isNew ? 'Registrar producto' : 'Guardar cambios' }}</button>
        </div>
    </form>
</div>
@push('scripts')
<script>
    document.querySelectorAll('[data-toggle-target]').forEach((el) => el.addEventListener('change', () => {
        document.querySelector(el.dataset.toggleTarget).hidden = ! el.checked;
    }));
</script>
@endpush
