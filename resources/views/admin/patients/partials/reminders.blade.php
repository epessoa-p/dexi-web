{{-- Pestaña «Recordatorios» de la ficha: controles programados, recordar la próxima cita y lo que se le envió. --}}
@php
    $followups = $reminderData['followups'];
    $sentList = $reminderData['communications'];
    $next = $reminderData['nextAppointment'];
    $kinds = \App\Support\Communications::KINDS;
    $canSend = $can('communications.create');
@endphp
<div class="row g-3">
    <div class="col-lg-7">
        @if($next && $canSend)
            <div class="card mb-3">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <span class="avatar" style="--tone: {{ $kinds['recordatorio'][2] }}"><i class="bi bi-calendar-check"></i></span>
                    <div class="flex-fill">
                        <div class="fw-semibold">Próxima cita: {{ ucfirst($next->starts_at->locale('es')->translatedFormat('l j \d\e F, H:i')) }}</div>
                        <div class="small text-muted">{{ collect([$next->title, $next->professional?->full_name, $next->status_label])->filter()->implode(' · ') }}</div>
                    </div>
                    @include('admin.communications.partials.sender', ['kind' => 'recordatorio', 'patient' => $p, 'message' => $next->reminderMessage(),
                        'refs' => ['appointment_id' => $next->id], 'sent' => $sentList->where('kind', 'recordatorio')->firstWhere('appointment_id', $next->id), 'label' => 'Recordar'])
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header"><i class="bi bi-arrow-repeat"></i> Seguimientos y controles</div>
            @if($canSend && $p->active)
                <form method="POST" action="{{ route('communications.followups.store', $p) }}" class="card-body border-bottom">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label small" for="fu-reason">Motivo</label>
                            <input type="text" id="fu-reason" name="reason" class="form-control" list="fu-reasons" maxlength="150" value="{{ old('reason', 'Control de 6 meses') }}" required>
                            <datalist id="fu-reasons">@foreach(\App\Models\PatientFollowup::REASONS as $reason)<option value="{{ $reason }}">@endforeach</datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small" for="fu-date">Fecha <span class="text-muted fw-normal">(o un plazo)</span></label>
                            <input type="date" id="fu-date" name="due_date" class="form-control" min="{{ today()->toDateString() }}" value="{{ old('due_date') }}">
                        </div>
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-1" role="group" aria-label="Plazo rápido">
                                @foreach(\App\Models\PatientFollowup::QUICK as $quickLabel => $modifier)
                                    <input type="radio" class="btn-check" name="in" id="fu-in-{{ $loop->index }}" value="{{ $modifier }}" autocomplete="off" @checked(old('in', '+6 months') === $modifier && ! old('due_date'))>
                                    <label class="btn btn-sm btn-outline-primary" for="fu-in-{{ $loop->index }}">en {{ $quickLabel }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <input type="text" name="notes" class="form-control" maxlength="1000" placeholder="Nota (opcional)" value="{{ old('notes') }}" aria-label="Nota">
                            <button class="btn btn-primary text-nowrap" type="submit"><i class="bi bi-plus-lg"></i> Programar</button>
                        </div>
                    </div>
                </form>
            @endif
            <ul class="list-group list-group-flush">
                @forelse($followups as $f)
                    <li class="list-group-item d-flex flex-wrap align-items-center gap-2 {{ $f->isPending() ? '' : 'text-muted' }}">
                        <div class="flex-fill">
                            <span class="fw-semibold">{{ $f->reason }}</span>
                            <span class="badge bg-{{ \App\Models\PatientFollowup::STATUSES[$f->status][1] }}{{ $f->status === 'pendiente' ? ' text-dark' : '' }} ms-1">{{ \App\Models\PatientFollowup::STATUSES[$f->status][0] }}</span>
                            <div class="small {{ $f->isOverdue() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ $f->due_date->format('d/m/Y') }}{{ $f->isOverdue() ? ' · vencido' : '' }}
                                @if($f->notes) · {{ $f->notes }}@endif
                                @if($f->closer) · cerró {{ $f->closer->name }}@endif
                            </div>
                        </div>
                        @if($canSend)
                            <form method="POST" action="{{ route('communications.followups.close', $f) }}" class="m-0 d-flex gap-1">
                                @csrf
                                @if($f->isPending())
                                    @if($f->due_date->lte(today()))
                                        @include('admin.communications.partials.sender', ['kind' => 'seguimiento', 'patient' => $p,
                                            'message' => \App\Support\Communications::render('seguimiento', $p, ['{motivo}' => mb_strtolower($f->reason)]),
                                            'refs' => ['followup_id' => $f->id], 'sent' => $sentList->firstWhere('followup_id', $f->id), 'class' => 'btn-sm', 'label' => 'Enviar'])
                                    @endif
                                    <button class="btn btn-sm btn-light" name="status" value="hecho"><i class="bi bi-check2"></i> Hecho</button>
                                    <button class="btn btn-sm btn-light text-muted" name="status" value="descartado" title="Descartar"><i class="bi bi-x"></i></button>
                                @else
                                    <button class="btn btn-sm btn-light" name="status" value="pendiente" title="Reabrir"><i class="bi bi-arrow-counterclockwise"></i></button>
                                @endif
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="list-group-item small text-muted">Sin controles programados. Programa el próximo al terminar el tratamiento: el día que toque aparecerá en «Para enviar hoy».</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> Mensajes enviados</div>
            <ul class="list-group list-group-flush">
                @forelse($sentList as $c)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2 small">
                            <span class="fw-semibold"><i class="bi {{ $kinds[$c->kind][1] ?? 'bi-chat' }}" style="color: {{ $kinds[$c->kind][2] ?? 'inherit' }}"></i> {{ $c->kind_label }}</span>
                            <span class="text-muted">{{ $c->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                        <div class="small text-muted mt-1" style="white-space: pre-wrap;">{{ $c->message }}</div>
                        @if($c->user)<div class="small text-muted mt-1">Envió: {{ $c->user->name }}</div>@endif
                    </li>
                @empty
                    <li class="list-group-item small text-muted">Todavía no se le envió ningún mensaje desde Dexi.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
