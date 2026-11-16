{{-- Pestañas que llegan con los próximos módulos: explican qué va a haber, sin pantalla rota. --}}
@php
    $soon = [
        'odontograma' => ['Odontograma', 'bi-grid-3x3', [
            'Dentición adulta (32) y temporal (20), con las 5 caras de cada pieza',
            'Caries, obturaciones, ausencias, coronas, endodoncias, implantes…',
            'Estado inicial frente al actual, e historial por pieza',
        ]],
        'historia' => ['Historia clínica', 'bi-journal-medical', [
            'Cada consulta: motivo, examen, diagnóstico y lo que se hizo',
            'Recetas e indicaciones imprimibles',
            'Fotos y archivos de cada atención',
        ]],
        'tratamiento' => ['Plan de tratamiento', 'bi-clipboard2-check', [
            'Presupuesto por pieza con el tarifario de la clínica',
            'Aprobación del paciente y avance de cada procedimiento',
            'Saldo del tratamiento y cobro por avance',
        ]],
        'citas' => ['Citas', 'bi-calendar-event', [
            'Citas pasadas y próximas, con su estado',
            'Agendar desde la ficha, en un clic',
            'Inasistencias y reprogramaciones',
        ]],
        'pagos' => ['Pagos', 'bi-cash-stack', [
            'Ventas, abonos y saldo pendiente del paciente',
            'Recibos imprimibles',
        ]],
        'recordatorios' => ['Recordatorios', 'bi-bell', [
            'Recordatorio de cita por WhatsApp o correo',
            'Controles periódicos y saludos de cumpleaños',
        ]],
    ];
    [$title, $icon, $items] = $soon[$key];
@endphp
<div class="card">
    <div class="soon-panel">
        <div class="empty-icon"><i class="bi {{ $icon }}"></i></div>
        <h5 class="mb-1">{{ $title }} llega pronto</h5>
        <p class="text-muted small mb-3">Esta pestaña se activa con el próximo módulo. Tendrá:</p>
        <ul class="mb-0">
            @foreach($items as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </div>
</div>
