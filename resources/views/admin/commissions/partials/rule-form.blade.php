{{-- Campos de una regla de comisión (nueva o edición). $rule puede ser null. --}}
@php
    $r = $rule;
    $sym = \App\Support\Money::symbol($tenantCompany);
    $applies = $r?->applies_to ?? 'todos';
    $type = $r?->type ?? 'porcentaje';
    $uid = $r?->id ?? 'new';
@endphp
<div class="d-flex flex-column gap-3 js-rule-form">
    <div>
        <label class="form-label" for="r-{{ $uid }}-who">¿A quién?</label>
        <select id="r-{{ $uid }}-who" name="personal_id" class="form-select">
            <option value="">Todo el equipo</option>
            @foreach($professionals as $pro)
                <option value="{{ $pro->id }}" @selected($r?->personal_id === $pro->id)>{{ $pro->full_name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label d-block">¿Sobre qué?</label>
        <div class="btn-group w-100 flex-wrap" role="group">
            @foreach(\App\Models\CommissionRule::APPLIES_TO as $key => $label)
                <input type="radio" class="btn-check js-applies" name="applies_to" id="r-{{ $uid }}-a-{{ $key }}" value="{{ $key }}" @checked($applies === $key)>
                <label class="btn btn-outline-primary btn-sm" for="r-{{ $uid }}-a-{{ $key }}">{{ $label }}</label>
            @endforeach
        </div>
    </div>
    <div class="js-when" data-when="procedimiento" @if($applies !== 'procedimiento') hidden @endif>
        <label class="form-label" for="r-{{ $uid }}-proc">Procedimiento</label>
        <select id="r-{{ $uid }}-proc" name="procedure_id" class="form-select">
            <option value="">Elige…</option>
            @foreach($procedures as $proc)
                <option value="{{ $proc->id }}" @selected($r?->procedure_id === $proc->id)>{{ $proc->name }}@if($proc->code) ({{ $proc->code }})@endif</option>
            @endforeach
        </select>
    </div>
    <div class="js-when" data-when="especialidad" @if($applies !== 'especialidad') hidden @endif>
        <label class="form-label" for="r-{{ $uid }}-spec">Especialidad</label>
        <select id="r-{{ $uid }}-spec" name="specialty_id" class="form-select">
            <option value="">Elige…</option>
            @foreach($specialties as $spec)
                <option value="{{ $spec->id }}" @selected($r?->specialty_id === $spec->id)>{{ $spec->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="row g-2">
        <div class="col-7">
            <label class="form-label d-block">Paga</label>
            <div class="btn-group w-100" role="group">
                @foreach(\App\Models\CommissionRule::TYPES as $key => $label)
                    <input type="radio" class="btn-check js-type" name="type" id="r-{{ $uid }}-t-{{ $key }}" value="{{ $key }}" @checked($type === $key)>
                    <label class="btn btn-outline-primary btn-sm" for="r-{{ $uid }}-t-{{ $key }}">{{ $label }}</label>
                @endforeach
            </div>
        </div>
        <div class="col-5">
            <label class="form-label" for="r-{{ $uid }}-value">Valor</label>
            <div class="input-group">
                <span class="input-group-text js-prefix" @if($type === 'porcentaje') hidden @endif>{{ $sym }}</span>
                <input type="number" id="r-{{ $uid }}-value" name="value" class="form-control" step="0.01" min="0" value="{{ $r ? (float) $r->value : '' }}" placeholder="{{ $type === 'porcentaje' ? '30' : '50' }}" required>
                <span class="input-group-text js-suffix" @if($type !== 'porcentaje') hidden @endif>%</span>
            </div>
        </div>
    </div>
    <div>
        <label class="form-label" for="r-{{ $uid }}-notes">Nota <span class="text-muted fw-normal">(opcional)</span></label>
        <input type="text" id="r-{{ $uid }}-notes" name="notes" class="form-control" maxlength="255" value="{{ $r?->notes }}">
    </div>
    @if($r)
        <div class="form-check form-switch">
            <input type="hidden" name="active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="r-{{ $uid }}-active" name="active" value="1" @checked($r->active)>
            <label class="form-check-label" for="r-{{ $uid }}-active">Activa</label>
        </div>
    @endif
</div>
