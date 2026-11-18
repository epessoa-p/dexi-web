<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\ReportController as WebReportController;
use App\Support\Money;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El resumen para el dueño en la app: utilidad, cobrado, gastos y la
 * comparación con el período anterior; lo más importante en lenguaje claro,
 * «Para revisar hoy» y la producción por profesional. Mismos cálculos y
 * permisos que Reportes de la web (el dinero pide `reports.finance`).
 * Los reportes detallados y el Excel siguen en la web.
 */
class ReportController extends WebReportController
{
    public function summaryApi(Request $request): JsonResponse
    {
        abort_unless(isset($this->sections()['resumen']), 403, 'Tu rol no ve las cifras de dinero de la clínica.');

        $period = ReportPeriod::fromRequest($request);
        $reports = new ReportData($period);
        $data = $this->summary($reports, $period);
        $finance = $data['finance'];
        $company = app(Tenancy::class)->company();

        return response()->json([
            'period' => [
                'preset' => $period->preset,
                'label' => $period->label(),
                'from' => $period->from->toDateString(),
                'to' => $period->to->toDateString(),
                'previous' => $period->previousRangeLabel(),
                'presets' => collect(ReportPeriod::PRESETS)->except('all')->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ],
            'totals' => $finance['totals'],
            'deltas' => $finance['deltas'],
            'chart' => [
                'labels' => $finance['chart']['labels'],
                'income' => $finance['chart']['series'][0]['values'],
                'expenses' => $finance['chart']['series'][1]['values'],
            ],
            'by_method' => collect($finance['byMethod'])->map(fn ($m) => ['label' => $m['label'], 'value' => $m['value']])->filter(fn ($m) => $m['value'] > 0)->values(),
            'receivable' => $finance['receivable'],
            'receivable_overdue' => $finance['receivableOverdue'],
            'payable' => $finance['payable'],
            // En la app, el texto lleva **negritas** en lugar de HTML.
            'insights' => collect($data['insights'])->map(fn ($i) => [
                'icon' => $i['icon'],
                'text' => html_entity_decode(strip_tags(str_replace(['<strong>', '</strong>'], '**', $i['html'])), ENT_QUOTES),
            ])->values(),
            'attention' => collect($data['attention'])->map(fn ($a) => [
                'key' => self::attentionKey($a['icon']),
                'tone' => $a['tone'],
                'text' => $a['text'],
            ])->values(),
            'professionals' => $company?->planAllows('agenda') || $company?->planAllows('historia_clinica')
                ? $reports->professionals()->take(10)->map(fn ($r) => [
                    'id' => $r['professional']->id,
                    'name' => $r['professional']->full_name,
                    'consultations' => $r['consultations'],
                    'patients' => $r['patients'],
                    'done' => $r['done'],
                    'commissions' => $r['commissions'],
                    'attendance' => $r['attendance'],
                ])->values()
                : [],
        ]);
    }

    /** Las secciones que este usuario puede ver (según sus permisos y el plan), los períodos y los consultorios. */
    public function sectionsApi(): JsonResponse
    {
        $sections = $this->sections();
        abort_if($sections === [], 403, 'No tienes reportes disponibles en esta clínica.');

        return response()->json([
            'sections' => collect($sections)->map(fn ($s, $key) => ['key' => $key, 'label' => $s['label'], 'question' => $s['question']])->values(),
            'can_money' => $this->can('reports.finance'),
            'presets' => collect(ReportPeriod::PRESETS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'branches' => \App\Models\Branch::where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
        ]);
    }

    /**
     * Una sección como bloques que la app dibuja igual para todas:
     * kpis (indicador + comparación), charts (series por día/mes) y lists
     * (ranking con su parte del total). Sin `reports.finance` no van cifras de dinero.
     */
    public function sectionApi(Request $request, string $section): JsonResponse
    {
        $sections = $this->sections();
        abort_unless(isset($sections[$section]), 403, 'No tienes acceso a este reporte.');
        if ($section === 'resumen') {
            return $this->summaryApi($request);
        }

        $period = ReportPeriod::fromRequest($request);
        $reports = new ReportData($period);
        $money = $this->can('reports.finance');

        $blocks = match ($section) {
            'finanzas' => $this->financeBlocks($reports->finance()),
            'ventas' => $this->salesBlocks($reports->sales()),
            'pacientes' => $this->clinicBlocks($reports->clinic(), $money),
            'profesionales' => $this->professionalBlocks($reports->professionals(), $money),
            'inventario' => $this->inventoryBlocks($reports->inventory(), $money),
            default => abort(404),
        };

        return response()->json([
            'section' => $section,
            'label' => $sections[$section]['label'],
            'question' => $sections[$section]['question'],
            'can_money' => $money,
            'period' => [
                'preset' => $period->preset,
                'label' => $period->label(),
                'previous' => $period->previousRangeLabel(),
                'branch_id' => $period->branchId,
            ],
        ] + $blocks + ['kpis' => [], 'charts' => [], 'lists' => []]);
    }

    // ── Bloques por sección ───────────────────────────────────────────────

    private static function kpi(string $label, float|int|null $value, string $format, ?array $delta = null, ?string $hint = null, ?string $tone = null): array
    {
        return compact('label', 'value', 'format', 'delta', 'hint', 'tone');
    }

    private static function chart(string $title, array $chart): array
    {
        return [
            'title' => $title,
            'format' => $chart['format'],
            'labels' => $chart['labels'],
            'series' => collect($chart['series'])->map(fn ($s) => ['name' => $s['name'], 'color' => $s['color'], 'values' => array_values($s['values'])])->values(),
        ];
    }

    /** @param  iterable<array{label: string, value: float|int, quantity?: ?float, share?: float}>  $rows */
    private static function list(string $title, iterable $rows, string $format, ?callable $hint = null, ?string $empty = null, int $limit = 10): array
    {
        return [
            'title' => $title,
            'format' => $format,
            'empty' => $empty ?? 'Sin datos en este período.',
            'rows' => collect($rows)->take($limit)->map(fn ($r) => [
                'label' => (string) $r['label'],
                'value' => $r['value'],
                'share' => isset($r['share']) ? round((float) $r['share'], 1) : null,
                'hint' => $hint ? $hint($r) : null,
            ])->values(),
        ];
    }

    private function financeBlocks(array $f): array
    {
        $t = $f['totals'];

        return [
            'kpis' => [
                self::kpi('Cobrado', $t['income'], 'money', $f['deltas']['income']),
                self::kpi('Productos e insumos', $t['cost'], 'money', $f['deltas']['cost']),
                self::kpi('Gastos', $t['expenses'], 'money', $f['deltas']['expenses']),
                self::kpi('Te queda', $t['profit'], 'money', $f['deltas']['profit'], tone: $t['profit'] < 0 ? 'danger' : null),
                self::kpi('Te deben', $f['receivable'], 'money', hint: $f['receivableOverdue'] > 0 ? Money::format($f['receivableOverdue']) . ' vencido' : null),
                self::kpi('Debes', $f['payable'], 'money', hint: 'a proveedores y laboratorios'),
            ],
            'charts' => [self::chart('Lo que entra y sale', $f['chart'])],
            'lists' => [
                self::list('Cobrado por método', $f['byMethod'], 'money'),
                self::list('Gastos por categoría', $f['expensesByCategory'], 'money'),
                self::list('Quién te debe', $f['debtors'], 'money', empty: 'Nadie debe.'),
                self::list('A quién le debes', $f['creditors'], 'money', empty: 'No debes nada.'),
            ],
        ];
    }

    private function salesBlocks(array $s): array
    {
        $qty = fn ($r) => ($r['quantity'] ?? null) ? rtrim(rtrim(number_format((float) $r['quantity'], 2, '.', ''), '0'), '.') . ' vendidos' : null;

        return [
            'kpis' => [
                self::kpi('Vendido', $s['total'], 'money', $s['deltas']['total']),
                self::kpi('Ventas', $s['count'], 'count', $s['deltas']['count']),
                self::kpi('Ticket promedio', $s['ticket'], 'money', $s['deltas']['ticket']),
            ],
            'charts' => [self::chart('Vendido por día', $s['chart'])],
            'lists' => [
                self::list('De dónde viene lo vendido', $s['byType'], 'money'),
                self::list('Tratamientos que más se venden', $s['procedures'], 'money', $qty),
                self::list('Productos', $s['products'], 'money', $qty, 'No se vendieron productos.'),
                self::list('Por especialidad', $s['specialties'], 'money'),
                self::list('Por usuario que cobró', $s['users'], 'money'),
            ],
        ];
    }

    private function clinicBlocks(array $c, bool $money): array
    {
        $a = $c['appointments'];
        $plans = $c['plans'];

        return [
            'kpis' => array_values(array_filter([
                self::kpi('Consultas', $c['count'], 'count', $c['deltas']['count'], "{$c['patients']} pacientes"),
                self::kpi('Pacientes nuevos', $c['newPatients'], 'count', $c['deltas']['newPatients']),
                self::kpi('Asistencia', $a['attendance'], 'percent', hint: "{$a['attended']} vinieron · {$a['noShow']} faltaron", tone: $a['attendance'] !== null && $a['attendance'] < 80 ? 'warning' : null),
                self::kpi('Presupuestos aceptados', $plans['acceptance'], 'percent',
                    hint: "{$plans['approved']} de {$plans['presented']} presentados" . ($money && $plans['approvedValue'] > 0 ? ' · ' . Money::format($plans['approvedValue']) : '')),
            ])),
            'charts' => [self::chart('Consultas por día', $c['chart'])],
            'lists' => [
                self::list('Citas por estado', $a['parts'], 'count', empty: 'No hubo citas.'),
                self::list('Diagnósticos más frecuentes', $c['diagnoses'], 'count'),
                self::list('Procedimientos realizados', $c['procedures'], 'count'),
                self::list('Cómo nos conocieron (nuevos)', $c['referrals'], 'count', empty: 'No hubo pacientes nuevos.'),
            ],
        ];
    }

    private function professionalBlocks($rows, bool $money): array
    {
        $detail = fn ($r) => collect([
            "{$r['consultations']} consultas",
            "{$r['patients']} pacientes",
            "{$r['procedures']} procedimientos",
            $r['attendance'] !== null ? "asistencia {$r['attendance']} %" : null,
            $money && $r['sold'] > 0 ? 'vendido ' . Money::format($r['sold']) : null,
            $money && $r['commissions'] > 0 ? 'comisiones ' . Money::format($r['commissions']) : null,
        ])->filter()->implode(' · ');

        $mapped = $rows->map(fn ($r) => $r + ['label' => $r['professional']->full_name, 'value' => $money ? $r['done'] : $r['consultations']]);

        return [
            'kpis' => [
                self::kpi('Profesionales con actividad', $rows->count(), 'count'),
                $money ? self::kpi('Atendido entre todos', round($rows->sum('done'), 2), 'money') : self::kpi('Consultas entre todos', $rows->sum('consultations'), 'count'),
            ],
            'lists' => [
                self::list($money ? 'Producción (lo atendido)' : 'Consultas', $mapped->sortByDesc('value')->values(), $money ? 'money' : 'count', $detail, 'Sin actividad en este período.', 50),
            ],
        ];
    }

    private function inventoryBlocks(array $i, bool $money): array
    {
        return [
            'kpis' => array_values(array_filter([
                $money ? self::kpi('Valor del inventario', $i['value'], 'money', hint: 'al costo de compra') : null,
                self::kpi('Productos activos', $i['count'], 'count'),
                self::kpi('Bajo el mínimo', count($i['low']), 'count', tone: count($i['low']) > 0 ? 'danger' : null),
                self::kpi('Lotes por vencer', $i['expiring']->count(), 'count', tone: $i['expiring']->count() > 0 ? 'warning' : null),
            ])),
            'lists' => array_values(array_filter([
                self::list('Bajo el mínimo', collect($i['low'])->map(fn ($r) => ['label' => $r['label'], 'value' => $r['stock'], 'min' => $r['min'], 'unit' => $r['unit']]), 'count',
                    fn ($r) => 'mínimo ' . rtrim(rtrim(number_format($r['min'], 2, '.', ''), '0'), '.') . ' ' . $r['unit'], 'Nada bajo el mínimo.', 30),
                self::list('Vencidos o por vencer', $i['expiring']->map(fn ($b) => ['label' => $b->product?->name ?? '', 'value' => (float) $b->quantity, 'expires' => $b->expires_at, 'lot' => $b->lot, 'expired' => $b->isExpired()]), 'count',
                    fn ($r) => ($r['expired'] ? 'venció el ' : 'vence el ') . $r['expires']?->format('d/m/Y') . ($r['lot'] ? " · lote {$r['lot']}" : ''), 'No hay lotes vencidos ni por vencer.', 30),
                $money ? self::list('Valor por categoría', $i['byCategory'], 'money') : null,
                self::list('Insumos gastados en procedimientos', $money ? $i['consumed'] : collect($i['consumed'])->map(fn ($r) => ['label' => $r['label'], 'value' => $r['quantity'] ?? 0])->filter(fn ($r) => $r['value'] > 0),
                    $money ? 'money' : 'count', $money ? fn ($r) => ($r['quantity'] ?? null) ? rtrim(rtrim(number_format((float) $r['quantity'], 2, '.', ''), '0'), '.') . ' usados' : null : null),
                self::list('Movimientos por motivo', collect($i['movements'])->map(fn ($m) => ['label' => $m['label'], 'value' => $m['in'] + $m['out'], 'in' => $m['in'], 'out' => $m['out']])->sortByDesc('value'), 'count',
                    fn ($r) => 'entró ' . rtrim(rtrim(number_format($r['in'], 2, '.', ''), '0'), '.') . ' · salió ' . rtrim(rtrim(number_format($r['out'], 2, '.', ''), '0'), '.')),
            ])),
        ];
    }

    /** La pantalla de la app a la que lleva cada aviso de «Para revisar hoy». */
    private static function attentionKey(string $icon): string
    {
        return match ($icon) {
            'bi-exclamation-octagon' => 'receivables',
            'bi-gem' => 'lab_late',
            'bi-truck' => 'payables',
            'bi-box-seam' => 'low_stock',
            'bi-hourglass-bottom' => 'expiring',
            'bi-arrow-repeat' => 'followups',
            'bi-percent' => 'commissions',
            default => 'other',
        };
    }
}
