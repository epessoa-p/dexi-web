@php
    $a = $data['appointments'];
    $pl = $data['plans'];
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Consultas', 'value' => $num($data['count']), 'delta' => $data['deltas']['count'],
        'hint' => $data['patients'] . ' ' . ($data['patients'] === 1 ? 'paciente atendido' : 'pacientes atendidos')])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Pacientes nuevos', 'value' => $num($data['newPatients']), 'delta' => $data['deltas']['newPatients'], 'url' => route('patients.index')])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Asistencia a citas', 'value' => $a['attendance'] !== null ? $a['attendance'] . ' %' : '—',
        'hint' => $a['noShow'] . ' no ' . ($a['noShow'] === 1 ? 'asistió' : 'asistieron') . ' · ' . $a['cancelled'] . ' ' . ($a['cancelled'] === 1 ? 'cancelada' : 'canceladas')])</div>
    <div class="col-6 col-xl-3">@include('admin.reports.partials.tile', ['label' => 'Presupuestos aceptados', 'value' => $pl['acceptance'] !== null ? $pl['acceptance'] . ' %' : '—',
        'hint' => $pl['approved'] . ' de ' . $pl['presented'] . ' presentados' . ($canMoney && $pl['approvedValue'] > 0 ? ' · ' . $money($pl['approvedValue']) : ''), 'url' => route('treatment-plans.index')])</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        @include('admin.reports.partials.chart', ['title' => 'Consultas por período', 'chart' => $data['chart'], 'empty' => 'Sin consultas en este período.'])
    </div>
    <div class="col-lg-5">
        <div class="rpt-card">
            <div class="rpt-head">
                <h3 class="rpt-card-title">¿Qué pasó con las citas?</h3>
                <p class="rpt-card-sub">{{ $a['total'] }} {{ $a['total'] === 1 ? 'cita' : 'citas' }} en el período.</p>
            </div>
            @if(collect($a['parts'])->isEmpty())
                @include('admin.reports.partials.empty', ['text' => 'Sin citas en este período.'])
            @else
                <div class="rpt-segments" role="img" aria-label="Citas por estado">
                    @foreach($a['parts'] as $part)
                        <div class="rpt-segment" style="flex: {{ number_format($part['share'], 2, '.', '') }} 1 0; background: {{ $part['color'] }}" title="{{ $part['label'] }}: {{ $part['value'] }}">{{ $part['share'] >= 12 ? round($part['share']) . ' %' : '' }}</div>
                    @endforeach
                </div>
                <ul class="rpt-legend">
                    @foreach($a['parts'] as $part)
                        <li><span class="rpt-key" style="background: {{ $part['color'] }}"></span>{{ $part['label'] }} <strong>{{ $part['value'] }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        @include('admin.reports.partials.ranked', ['title' => 'Diagnósticos frecuentes', 'rows' => $data['diagnoses'], 'format' => 'count', 'empty' => 'Sin diagnósticos en este período.'])
    </div>
    <div class="col-lg-4">
        @include('admin.reports.partials.ranked', ['title' => 'Procedimientos realizados', 'rows' => $data['procedures'], 'format' => 'count', 'empty' => 'Sin procedimientos en este período.'])
    </div>
    <div class="col-lg-4">
        @include('admin.reports.partials.ranked', ['title' => '¿Cómo nos conocieron?', 'sub' => 'Pacientes nuevos del período.', 'rows' => $data['referrals'], 'format' => 'count', 'showShare' => true, 'empty' => 'Sin pacientes nuevos en este período.'])
    </div>
</div>
