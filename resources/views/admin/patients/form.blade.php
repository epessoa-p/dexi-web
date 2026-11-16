{{--
    Alta y edición del paciente, por secciones. Parámetros: $patient (nuevo o
    existente), $branches. El bloque del tutor aparece solo si es menor de edad.
--}}
@php
    $p = $patient;
    $isNew = ! $p->exists;
    // «Registrar "Ana Pérez"» desde la búsqueda sin resultados.
    [$prefFirst, $prefLast] = $isNew && request('name') ? array_pad(explode(' ', trim(request('name')), 2), 2, '') : [null, null];
    $val = fn (string $field, $default = null) => old($field, $p->{$field} ?? $default);
    $conditions = old('conditions', $p->conditions ?? []);
    $birth = old('birth_date', $p->birth_date?->toDateString());
    $err = fn (string $field) => $errors->has($field) ? 'is-invalid' : '';
@endphp
<div class="container-fluid" style="max-width: 1040px;">
    <div class="page-header">
        <div>
            <a href="{{ $isNew ? route('patients.index') : route('patients.show', $p) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $isNew ? 'Pacientes' : $p->full_name }}</a>
            <h1 class="mt-1">{{ $isNew ? 'Nuevo paciente' : 'Editar paciente' }}</h1>
            <p class="page-subtitle">Solo nombres y apellidos son obligatorios: lo demás puedes completarlo después.</p>
        </div>
        @unless($isNew)<span class="badge bg-light text-dark border fs-6">{{ $p->code }}</span>@endunless
    </div>

    @if($errors->any())
        <div class="alert alert-danger d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <div>Revisa los campos marcados.@if($errors->has('guardian_name') || $errors->has('guardian_phone')) {{ $errors->first('guardian_name') ?: $errors->first('guardian_phone') }}@endif</div>
        </div>
    @endif

    <form method="POST" action="{{ $isNew ? route('patients.store') : route('patients.update', $p) }}" novalidate id="patient-form">
        @csrf
        @unless($isNew) @method('PUT') @endunless
        @if($isNew && request('return'))
            <input type="hidden" name="return" value="{{ request('return') }}">
            <input type="hidden" name="start" value="{{ request('start') }}">
            <input type="hidden" name="personal_id" value="{{ request('personal_id') }}">
        @endif

        {{-- 1. Datos personales --}}
        <section class="card form-section">
            <div class="card-header"><span class="section-icon"><i class="bi bi-person"></i></span> Datos personales</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="first_name">Nombres <span class="text-danger">*</span></label>
                    <input type="text" id="first_name" name="first_name" class="form-control {{ $err('first_name') }}" value="{{ old('first_name', $p->first_name ?? $prefFirst) }}" maxlength="100" required autofocus autocomplete="off">
                    @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="last_name">Apellidos <span class="text-danger">*</span></label>
                    <input type="text" id="last_name" name="last_name" class="form-control {{ $err('last_name') }}" value="{{ old('last_name', $p->last_name ?? $prefLast) }}" maxlength="100" required autocomplete="off">
                    @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="document_number">Documento</label>
                    <div class="input-group">
                        <select name="document_type" class="form-select" style="max-width: 9rem" aria-label="Tipo de documento">
                            @foreach(\App\Models\Patient::DOCUMENT_TYPES as $key => $label)
                                <option value="{{ $key }}" @selected($val('document_type', 'CI') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" id="document_number" name="document_number" class="form-control {{ $err('document_number') }}" value="{{ $val('document_number') }}" maxlength="30" inputmode="numeric">
                    </div>
                    @error('document_number')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="birth_date">Fecha de nacimiento</label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control {{ $err('birth_date') }}" value="{{ $birth }}" max="{{ now()->toDateString() }}">
                    <div class="form-text" id="age-hint" aria-live="polite"></div>
                    @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="gender">Sexo</label>
                    <select id="gender" name="gender" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\Patient::GENDERS as $key => $label)
                            <option value="{{ $key }}" @selected($val('gender') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="occupation">Ocupación</label>
                    <input type="text" id="occupation" name="occupation" class="form-control" value="{{ $val('occupation') }}" maxlength="100">
                </div>
                @if($branches->count() > 1)
                    <div class="col-md-6">
                        <label class="form-label" for="branch_id">Consultorio habitual</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">Cualquiera</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) $val('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </section>

        {{-- 2. Contacto --}}
        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-telephone"></i></span> Contacto</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="phone">Celular</label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="{{ $val('phone') }}" maxlength="30" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="whatsapp">WhatsApp <small class="text-muted fw-normal">(si es otro)</small></label>
                    <div class="input-group">
                        <span class="input-group-text text-success"><i class="bi bi-whatsapp"></i></span>
                        <input type="tel" id="whatsapp" name="whatsapp" class="form-control" value="{{ $val('whatsapp') }}" maxlength="30" placeholder="Con código de país: 591…">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="email">Correo</label>
                    <input type="email" id="email" name="email" class="form-control {{ $err('email') }}" value="{{ $val('email') }}" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="address">Dirección</label>
                    <input type="text" id="address" name="address" class="form-control" value="{{ $val('address') }}" maxlength="255">
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="emergency_contact">Contacto de emergencia</label>
                    <input type="text" id="emergency_contact" name="emergency_contact" class="form-control" value="{{ $val('emergency_contact') }}" maxlength="120" placeholder="Nombre y parentesco">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="emergency_phone">Teléfono de emergencia</label>
                    <input type="tel" id="emergency_phone" name="emergency_phone" class="form-control" value="{{ $val('emergency_phone') }}" maxlength="30">
                </div>
            </div>
        </section>

        {{-- 3. Tutor: solo para menores --}}
        <section class="card form-section" id="guardian-section" @if(! ($birth && now()->subYears(18)->lt($birth)) && ! $errors->has('guardian_name') && ! $errors->has('guardian_phone') && ! $val('guardian_name')) hidden @endif>
            <div class="card-header"><span class="section-icon" style="--tone:#ea580c"><i class="bi bi-person-hearts"></i></span> Tutor responsable <span class="section-hint">Obligatorio para menores de edad</span></div>
            <div class="card-body row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="guardian_name">Nombre del tutor <span class="text-danger">*</span></label>
                    <input type="text" id="guardian_name" name="guardian_name" class="form-control {{ $err('guardian_name') }}" value="{{ $val('guardian_name') }}" maxlength="120">
                    @error('guardian_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="guardian_relationship">Parentesco</label>
                    <input type="text" id="guardian_relationship" name="guardian_relationship" class="form-control" value="{{ $val('guardian_relationship') }}" maxlength="40" list="relationships" placeholder="Madre, padre…">
                    <datalist id="relationships"><option value="Madre"><option value="Padre"><option value="Abuelo/a"><option value="Tío/a"><option value="Hermano/a"><option value="Tutor legal"></datalist>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="guardian_phone">Teléfono del tutor <span class="text-danger">*</span></label>
                    <input type="tel" id="guardian_phone" name="guardian_phone" class="form-control {{ $err('guardian_phone') }}" value="{{ $val('guardian_phone') }}" maxlength="30">
                    @error('guardian_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>

        {{-- 4. Anamnesis --}}
        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#dc3545"><i class="bi bi-heart-pulse"></i></span> Antecedentes médicos
                <span class="section-hint">Lo marcado aparece como alerta en toda la ficha</span></div>
            <div class="card-body row g-3">
                <div class="col-12">
                    <label class="form-label d-block">¿Tiene alguna de estas condiciones?</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach(\App\Models\Patient::CONDITIONS as $key => [$label, $critical])
                            <label class="chip-check">
                                <input type="checkbox" name="conditions[]" value="{{ $key }}" @checked(in_array($key, $conditions, true))>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="allergies">Alergias</label>
                    <textarea id="allergies" name="allergies" rows="2" class="form-control" maxlength="1000" placeholder="Penicilina, látex, anestesia local…">{{ $val('allergies') }}</textarea>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        @foreach(['Penicilina', 'Látex', 'Anestesia local', 'AINEs (ibuprofeno)', 'Yodo'] as $allergen)
                            <button type="button" class="quick-fill" data-fill="allergies" data-value="{{ $allergen }}">+ {{ $allergen }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="medications">Medicación actual</label>
                    <textarea id="medications" name="medications" rows="2" class="form-control" maxlength="1000" placeholder="Qué toma y en qué dosis">{{ $val('medications') }}</textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="blood_type">Grupo sanguíneo</label>
                    <select id="blood_type" name="blood_type" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\Patient::BLOOD_TYPES as $type)
                            <option value="{{ $type }}" @selected($val('blood_type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end" id="pregnant-wrap">
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="pregnant" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="pregnant" name="pregnant" value="1" @checked($val('pregnant'))>
                        <label class="form-check-label" for="pregnant">Embarazo</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="medical_notes">Otros antecedentes</label>
                    <input type="text" id="medical_notes" name="medical_notes" class="form-control" value="{{ $val('medical_notes') }}" maxlength="3000" placeholder="Cirugías, internaciones, tratamientos…">
                </div>
            </div>
        </section>

        {{-- 5. Cómo llegó y notas --}}
        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#7c3aed"><i class="bi bi-megaphone"></i></span> Cómo nos conoció y notas</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="referral_source">Cómo nos conoció</label>
                    <select id="referral_source" name="referral_source" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\Patient::REFERRAL_SOURCES as $key => $label)
                            <option value="{{ $key }}" @selected($val('referral_source') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="referred_by">Recomendado por</label>
                    <input type="text" id="referred_by" name="referred_by" class="form-control" value="{{ $val('referred_by') }}" maxlength="120">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="insurance">Seguro o convenio</label>
                    <input type="text" id="insurance" name="insurance" class="form-control" value="{{ $val('insurance') }}" maxlength="120">
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notas internas</label>
                    <textarea id="notes" name="notes" rows="2" class="form-control" maxlength="3000" placeholder="Preferencias de horario, miedo al dentista…">{{ $val('notes') }}</textarea>
                </div>
                @unless($isNew)
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked($val('active', true))>
                            <label class="form-check-label" for="active">Paciente activo <small class="text-muted">(los inactivos no aparecen en la lista ni en las búsquedas)</small></label>
                        </div>
                    </div>
                @endunless
            </div>
        </section>

        <div class="sticky-actions">
            <a href="{{ $isNew ? route('patients.index') : route('patients.show', $p) }}" class="btn btn-light">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ $isNew ? 'Registrar paciente' : 'Guardar cambios' }}</button>
        </div>
    </form>
</div>

<script>
(function () {
    const birth = document.getElementById('birth_date');
    const hint = document.getElementById('age-hint');
    const guardian = document.getElementById('guardian-section');
    const gender = document.getElementById('gender');
    const pregnant = document.getElementById('pregnant-wrap');

    // Edad en vivo y tutor solo para menores.
    const updateAge = () => {
        if (!birth.value) { hint.textContent = ''; return; }
        const b = new Date(birth.value + 'T00:00:00'), now = new Date();
        let years = now.getFullYear() - b.getFullYear();
        if (now < new Date(now.getFullYear(), b.getMonth(), b.getDate())) years--;
        const months = (now.getFullYear() - b.getFullYear()) * 12 + now.getMonth() - b.getMonth() - (now.getDate() < b.getDate() ? 1 : 0);
        hint.textContent = years >= 1 ? `${years} ${years === 1 ? 'año' : 'años'}` : `${Math.max(0, months)} ${months === 1 ? 'mes' : 'meses'}`;
        if (years < 18) { guardian.hidden = false; hint.textContent += ' · menor de edad'; }
    };
    birth.addEventListener('change', updateAge);
    updateAge();

    // «Embarazo» no aplica si el sexo es masculino.
    const updatePregnant = () => { pregnant.hidden = gender.value === 'M'; };
    gender.addEventListener('change', updatePregnant);
    updatePregnant();

    // Botones «+ Penicilina»: agregan el texto a las alergias.
    document.querySelectorAll('[data-fill]').forEach((button) => button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.fill);
        const items = field.value.split(',').map((s) => s.trim()).filter(Boolean);
        if (!items.includes(button.dataset.value)) items.push(button.dataset.value);
        field.value = items.join(', ');
        field.focus();
    }));

    // Ir al primer campo con error.
    document.querySelector('#patient-form .is-invalid')?.focus();
})();
</script>
