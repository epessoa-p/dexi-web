{{--
    «¿De dónde sale el dinero?»: una cuenta (con su saldo) o una caja abierta
    (con el efectivo que debería haber en el cajón). Lo usan gastos y pagos a
    proveedores. Parámetros: $source (PaymentSource::options()), $id (prefijo único).
--}}
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $sources = $source['sources'];
    $chosen = old('source', array_key_first($sources));
    $accountId = (string) old('finance_account_id', $source['preferred_account_id']);
    $sessionId = (string) old('cash_session_id', $source['preferred_session_id']);
    $icons = ['account' => 'bi-bank', 'cash' => 'bi-cash-coin', 'external' => 'bi-box-arrow-up-right'];
@endphp
<div class="source-picker" data-source-picker>
    <label class="form-label">¿De dónde sale el dinero? <span class="text-danger">*</span></label>
    <div class="btn-group w-100 mb-2" role="group">
        @foreach($sources as $key => $label)
            <input type="radio" class="btn-check" name="source" id="{{ $id }}-src-{{ $key }}" value="{{ $key }}" autocomplete="off" @checked($chosen === $key)>
            <label class="btn btn-outline-primary" for="{{ $id }}-src-{{ $key }}"><i class="bi {{ $icons[$key] }}"></i> {{ $label }}</label>
        @endforeach
    </div>

    @isset($sources['account'])
        <div class="source-pane" data-pane="account" @if($chosen !== 'account') hidden @endif>
            @forelse($source['accounts'] as $account)
                <label class="source-option">
                    <input type="radio" class="form-check-input" name="finance_account_id" value="{{ $account->id }}" @checked($accountId === (string) $account->id)>
                    <i class="bi {{ $account->icon }}"></i>
                    <span class="flex-fill">{{ $account->name }} <small class="text-muted">{{ $account->type_label }}</small></span>
                    <strong>{{ $money($account->balance) }}</strong>
                </label>
            @empty
                <div class="alert alert-warning small mb-0">
                    No hay cuentas activas. <a href="{{ route('finance-accounts.index') }}">Crea una cuenta</a> o paga desde la caja.
                </div>
            @endforelse
            @error('finance_account_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    @endisset

    @isset($sources['cash'])
        <div class="source-pane" data-pane="cash" @if($chosen !== 'cash') hidden @endif>
            @forelse($source['sessions'] as $session)
                <label class="source-option">
                    <input type="radio" class="form-check-input" name="cash_session_id" value="{{ $session->id }}" @checked($sessionId === (string) $session->id)>
                    <i class="bi bi-cash-coin"></i>
                    <span class="flex-fill">{{ $session->caja?->name }} <small class="text-muted">turno {{ $session->id }} · abierto {{ $session->opened_at?->format('d/m H:i') }}</small></span>
                    <span class="text-end"><small class="text-muted d-block lh-1">efectivo</small><strong>{{ $money($session->expectedCash()) }}</strong></span>
                </label>
            @empty
                <div class="alert alert-warning small mb-0">
                    No hay ninguna caja abierta. @if(Route::has('cash.index'))<a href="{{ route('cash.index') }}">Abre una caja</a>@endif @isset($sources['account']) o paga desde una cuenta.@endisset
                </div>
            @endforelse
            <div class="form-text">Lo que sale de la caja es efectivo: el arqueo lo descuenta.</div>
            @error('cash_session_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    @endisset

    @isset($sources['external'])
        <div class="source-pane" data-pane="external" @if($chosen !== 'external') hidden @endif>
            <div class="form-text">Se anota el gasto sin mover dinero de la caja (por ejemplo, un pago desde el banco).</div>
        </div>
    @endisset
    @error('source')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
</div>

@once
    <style>
        .source-option { display: flex; align-items: center; gap: .6rem; padding: .55rem .75rem; border: 1px solid #e3e3e3; border-radius: .6rem; margin-bottom: .4rem; cursor: pointer; background: #fff; }
        .source-option:hover { border-color: #b9c9e8; }
        .source-option:has(input:checked) { border-color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), .06); box-shadow: 0 0 0 1px var(--bs-primary) inset; }
        .source-option .bi { color: #6c757d; }
    </style>
    <script>
        document.addEventListener('change', (event) => {
            if (event.target.name !== 'source') return;
            const picker = event.target.closest('[data-source-picker]');
            picker?.querySelectorAll('.source-pane').forEach((pane) => { pane.hidden = pane.dataset.pane !== event.target.value; });
        });
    </script>
@endonce
