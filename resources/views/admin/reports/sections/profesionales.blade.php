@php $rows = $data['rows']; @endphp

<div class="rpt-card">
    <div class="rpt-head">
        <h3 class="rpt-card-title">Producción por profesional</h3>
        <p class="rpt-card-sub">Atendido = valor de lo realizado en sus consultas. Vendido = sus líneas cobradas (con el descuento de la venta).</p>
    </div>
    @if($rows->isEmpty())
        @include('admin.reports.partials.empty', ['icon' => 'bi-person-badge', 'text' => 'Sin atenciones en este período.'])
    @else
        @php $maxDone = max((float) $rows->max('done'), 1); @endphp
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0 rpt-table">
                <thead><tr>
                    <th>Profesional</th><th class="text-end">Consultas</th><th class="text-end">Pacientes</th><th class="text-end">Procedimientos</th>
                    <th class="text-end">Asistencia</th>
                    @if($canMoney)<th style="min-width: 10rem">Atendido</th><th class="text-end">Vendido</th><th class="text-end">Comisiones</th>@endif
                </tr></thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr>
                            <td class="stack-full fw-semibold"><span class="rpt-key d-inline-block me-1" style="background: {{ $r['professional']->color }}"></span>{{ $r['professional']->full_name }}</td>
                            <td class="text-end" data-label="Consultas">{{ $r['consultations'] }}</td>
                            <td class="text-end" data-label="Pacientes">{{ $r['patients'] }}</td>
                            <td class="text-end" data-label="Procedimientos">{{ $r['procedures'] }}</td>
                            <td class="text-end" data-label="Asistencia">{{ $r['attendance'] !== null ? $r['attendance'] . ' %' : '—' }}<div class="small text-muted">{{ $r['appointments'] }} citas</div></td>
                            @if($canMoney)
                                <td data-label="Atendido">
                                    <div class="d-flex justify-content-between small"><span></span><strong>{{ $money($r['done']) }}</strong></div>
                                    <div class="rpt-rank-bar" style="width: {{ number_format($r['done'] / $maxDone * 100, 2, '.', '') }}%"></div>
                                </td>
                                <td class="text-end" data-label="Vendido">{{ $money($r['sold']) }}</td>
                                <td class="text-end" data-label="Comisiones">{{ (float) $r['commissions'] ? $money($r['commissions']) : '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
