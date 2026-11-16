{{-- Alta o edición de una cuenta en un modal. Parámetros: $account (null = alta), $modalId, $branches. --}}
@php
    $a = $account;
    $isNew = $a === null;
    // Si el envío falló, el modal vuelve a abrirse con lo que se escribió.
    $reopen = $errors->any() && old('_modal') === $modalId;
    $val = fn (string $field, $default = null) => $reopen ? old($field, $default) : ($a?->{$field} ?? $default);
@endphp
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true" @if($reopen) data-reopen @endif>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ $isNew ? route('finance-accounts.store') : route('finance-accounts.update', $a) }}">
            @csrf
            @unless($isNew) @method('PUT') @endunless
            <input type="hidden" name="_modal" value="{{ $modalId }}">
            <div class="modal-header">
                <h5 class="modal-title">{{ $isNew ? 'Nueva cuenta' : 'Editar cuenta' }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-12">
                    <label class="form-label">Nombre <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @if($reopen) @error('name') is-invalid @enderror @endif" value="{{ $val('name') }}" maxlength="80" placeholder="Banco Unión, Caja chica…" required>
                    @if($reopen) @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo <span class="text-danger">*</span></label>
                    <select name="type" class="form-select">
                        @foreach(\App\Models\FinanceAccount::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected($val('type', 'banco') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sucursal <small class="text-muted">(opcional)</small></label>
                    <select name="branch_id" class="form-select">
                        <option value="">Toda la clínica</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) $val('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Banco</label>
                    <input type="text" name="bank_name" class="form-control" value="{{ $val('bank_name') }}" maxlength="80">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nº de cuenta</label>
                    <input type="text" name="account_number" class="form-control" value="{{ $val('account_number') }}" maxlength="40">
                </div>
                @if($isNew)
                    <div class="col-12">
                        <label class="form-label">Saldo inicial</label>
                        <div class="input-group">
                            <span class="input-group-text">{{ \App\Support\Money::symbol($tenantCompany) }}</span>
                            <input type="number" name="opening_balance" step="0.01" min="0" class="form-control @if($reopen) @error('opening_balance') is-invalid @enderror @endif" value="{{ $val('opening_balance', 0) }}">
                        </div>
                        <div class="form-text">Lo que hay hoy en la cuenta. Queda como primer movimiento del extracto.</div>
                    </div>
                @endif
                <div class="col-12">
                    <label class="form-label">Notas</label>
                    <textarea name="notes" rows="2" class="form-control">{{ $val('notes') }}</textarea>
                </div>
                @unless($isNew)
                    <div class="col-12">
                        <input type="hidden" name="active" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="active" value="1" id="{{ $modalId }}-active" @checked($val('active', true))>
                            <label class="form-check-label" for="{{ $modalId }}-active">Activa (se puede usar para pagar y cobrar)</label>
                        </div>
                    </div>
                @endunless
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>
