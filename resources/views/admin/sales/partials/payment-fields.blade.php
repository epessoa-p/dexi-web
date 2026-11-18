{{-- Campos de un pago (abono): método, monto, referencia y caja. Parámetros: $methods, $sessions, $cashEnabled, $prefix, $amount. --}}
<div class="row g-2">
    <div class="col-sm-6">
        <label class="form-label small" for="{{ $prefix }}-method">Método</label>
        <select name="payment_method_id" id="{{ $prefix }}-method" class="form-select" required>
            @foreach($methods as $method)
                <option value="{{ $method->id }}">{{ $method->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-sm-6">
        <label class="form-label small" for="{{ $prefix }}-amount">Monto</label>
        <div class="input-group"><span class="input-group-text">{{ \App\Support\Money::symbol($tenantCompany) }}</span>
            <input type="number" name="amount" id="{{ $prefix }}-amount" step="0.5" min="0.01" max="{{ $amount }}" value="{{ $amount }}" class="form-control text-end" required></div>
    </div>
    <div class="col-sm-6">
        <input type="text" name="reference" class="form-control" maxlength="80" placeholder="Nº de comprobante (QR, tarjeta…)" aria-label="Referencia">
    </div>
    @if($cashEnabled && $sessions->count() > 1)
        <div class="col-sm-6">
            <select name="cash_session_id" class="form-select" aria-label="Caja">
                @foreach($sessions as $session)<option value="{{ $session->id }}">{{ $session->caja?->name }}</option>@endforeach
            </select>
        </div>
    @elseif($cashEnabled && $sessions->count() === 1)
        <input type="hidden" name="cash_session_id" value="{{ $sessions->first()->id }}">
    @endif
</div>
@if($cashEnabled && $sessions->isEmpty())
    <div class="small text-danger mt-2"><i class="bi bi-safe"></i> No hay caja abierta: ábrela para registrar el cobro.</div>
@endif
