<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Money;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\XlsxWriter;
use App\Support\Tenancy;

/**
 * Reportes (§12). Cada pantalla responde una pregunta de la clínica y todas
 * comparten período y consultorio. Lo que muestra dinero pide `reports.finance`:
 * quien solo ve lo clínico no ve cifras de dinero.
 */
class ReportController extends Controller
{
    public function index(?string $section = null)
    {
        $sections = $this->sections();
        abort_if($sections === [], 403, 'No tienes reportes disponibles en esta clínica.');

        if ($section === null) {
            return redirect()->route('reports.index', ['section' => array_key_first($sections)] + request()->query());
        }
        abort_unless(isset($sections[$section]), 403, 'No tienes acceso a este reporte.');

        $period = ReportPeriod::fromRequest(request());

        return view('admin.reports.index', [
            'sections' => $sections,
            'section' => $section,
            'period' => $period,
            'data' => $this->dataFor($section, $period),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
            'presets' => ReportPeriod::PRESETS,
            'canExport' => $this->can('reports.export'),
            'canMoney' => $this->can('reports.finance'),
            'chartMeta' => ['symbol' => Money::symbol(app(Tenancy::class)->company()), 'decimals' => 2],
        ]);
    }

    public function export(string $section)
    {
        abort_unless(isset($this->sections()[$section]), 403, 'No tienes acceso a este reporte.');

        $period = ReportPeriod::fromRequest(request());
        $writer = new XlsxWriter;
        foreach ($this->sheets($section, $this->dataFor($section, $period), $period) as [$title, $columns, $rows]) {
            $writer->addSheet($title, $columns, $rows);
        }

        return response()->download($writer->save(), sprintf('reporte-%s-%s_%s.xlsx', $section, $period->from->toDateString(), $period->to->toDateString()), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return array<string, array{label: string, icon: string, question: string}> */
    protected function sections(): array
    {
        $company = app(Tenancy::class)->company();
        $money = $this->can('reports.finance') && $company?->planAllows('ventas');
        $view = $this->can('reports.view') || $this->can('reports.finance');
        $clinical = $company?->planAllows('agenda') || $company?->planAllows('historia_clinica');

        return array_filter([
            'resumen' => $money ? ['label' => 'Resumen', 'icon' => 'bi-speedometer2', 'question' => '¿Cómo va la clínica?'] : null,
            'finanzas' => $money ? ['label' => 'Ingresos y egresos', 'icon' => 'bi-cash-stack', 'question' => '¿Cuánto dinero entró, cuánto salió y cuánto queda?'] : null,
            'ventas' => $money ? ['label' => 'Ventas', 'icon' => 'bi-receipt', 'question' => '¿Qué tratamientos y productos se venden más?'] : null,
            'pacientes' => $view && $clinical ? ['label' => 'Pacientes y citas', 'icon' => 'bi-people', 'question' => '¿Cuántos pacientes atendimos, cuántos faltaron y cuántos aceptan su presupuesto?'] : null,
            'profesionales' => $view && $clinical ? ['label' => 'Profesionales', 'icon' => 'bi-person-badge', 'question' => '¿Cuánto produce cada odontólogo?'] : null,
            'inventario' => $view && $company?->planAllows('inventario') ? ['label' => 'Inventario', 'icon' => 'bi-box-seam', 'question' => '¿Qué hay, qué se gasta y qué necesita atención?'] : null,
        ]);
    }

    protected function dataFor(string $section, ReportPeriod $period): array
    {
        $reports = new ReportData($period);

        return match ($section) {
            'finanzas' => $reports->finance(),
            'ventas' => $reports->sales(),
            'pacientes' => $reports->clinic(),
            'profesionales' => ['rows' => $reports->professionals()],
            'inventario' => $reports->inventory(),
            default => $this->summary($reports, $period),
        };
    }

    protected function summary(ReportData $reports, ReportPeriod $period): array
    {
        $company = app(Tenancy::class)->company();
        $finance = $reports->finance();
        $sales = $reports->sales();
        $clinic = $company?->planAllows('agenda') || $company?->planAllows('historia_clinica') ? $reports->clinic() : null;

        return [
            'finance' => $finance,
            'sales' => $sales,
            'clinic' => $clinic,
            'insights' => $this->insights($period, $finance, $sales, $clinic),
            'attention' => $this->attention($reports->pending()),
        ];
    }

    /** La lectura del período en lenguaje claro. @return array<int, array{icon: string, html: string}> */
    protected function insights(ReportPeriod $period, array $finance, array $sales, ?array $clinic): array
    {
        $money = fn ($value) => '<strong>' . e(Money::format($value)) . '</strong>';
        $strong = fn ($text) => '<strong>' . e($text) . '</strong>';
        $t = $finance['totals'];
        $versus = e((string) $period->previousLabel);

        if ($t['income'] <= 0 && $sales['count'] === 0 && ! ($clinic['count'] ?? 0)) {
            return [['icon' => 'bi-hourglass', 'html' => 'Todavía no hay cobros ni atenciones en este período. Prueba con otro rango de fechas.']];
        }

        $delta = $finance['deltas']['income'];
        $insights[] = match (true) {
            $delta !== null && $delta['pct'] !== null && $delta['pct'] > 0 => ['icon' => 'bi-graph-up-arrow', 'html' => "Cobraste {$money($t['income'])}, <strong>{$delta['pct']} % más</strong> que {$versus}."],
            $delta !== null && $delta['pct'] !== null && $delta['pct'] < 0 => ['icon' => 'bi-graph-down-arrow', 'html' => 'Cobraste ' . $money($t['income']) . ', <strong>' . abs($delta['pct']) . " % menos</strong> que {$versus}."],
            default => ['icon' => 'bi-cash-coin', 'html' => "Cobraste {$money($t['income'])} en este período."],
        };

        if ($t['profit'] < 0) {
            $insights[] = ['icon' => 'bi-exclamation-triangle', 'html' => 'Los costos y gastos superaron a lo cobrado por ' . $money(abs($t['profit'])) . '.'];
        } elseif ($t['income'] > 0) {
            $insights[] = ['icon' => 'bi-piggy-bank', 'html' => 'De cada 100 que entraron, quedaron <strong>' . round($t['profit'] / $t['income'] * 100) . '</strong> después de costos y gastos.'];
        }

        if ($top = $sales['procedures']->first()) {
            $insights[] = ['icon' => 'bi-trophy', 'html' => 'El tratamiento que más facturó fue ' . $strong($top['label']) . ' (' . $money($top['value']) . ').'];
        }

        if ($clinic && $clinic['appointments']['attendance'] !== null && $clinic['appointments']['noShow'] > 0) {
            $insights[] = ['icon' => 'bi-person-x', 'html' => "Asistió el <strong>{$clinic['appointments']['attendance']} %</strong> de los pacientes citados; faltaron <strong>{$clinic['appointments']['noShow']}</strong>."];
        } elseif ($clinic && $clinic['count'] > 0) {
            $insights[] = ['icon' => 'bi-clipboard2-pulse', 'html' => "Atendiste <strong>{$clinic['count']}</strong> consultas de <strong>{$clinic['patients']}</strong> pacientes."];
        }

        if ($clinic && $clinic['plans']['acceptance'] !== null) {
            $insights[] = ['icon' => 'bi-clipboard2-check', 'html' => "Se aprobó el <strong>{$clinic['plans']['acceptance']} %</strong> de los presupuestos presentados (" . $money($clinic['plans']['approvedValue']) . ' en tratamientos).'];
        }

        return $insights;
    }

    /** @return array<int, array{icon: string, tone: string, text: string, url: ?string}> */
    protected function attention(array $pending): array
    {
        $units = fn (int $n, string $one, string $many) => $n === 1 ? $one : "{$n} {$many}";
        $route = fn (string $name, array $params = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $params) : null;

        return array_values(array_filter([
            $pending['overdueSales'] ? ['icon' => 'bi-exclamation-octagon', 'tone' => 'critical',
                'text' => $units($pending['overdueSales'], 'Una deuda de paciente vencida', 'deudas de pacientes vencidas'), 'url' => $route('receivables.index')] : null,
            $pending['labLate'] ? ['icon' => 'bi-gem', 'tone' => 'critical',
                'text' => $units($pending['labLate'], 'Un trabajo de laboratorio atrasado', 'trabajos de laboratorio atrasados'), 'url' => $route('lab-orders.index', ['filter' => 'atrasados'])] : null,
            $pending['overduePurchases'] ? ['icon' => 'bi-truck', 'tone' => 'warning',
                'text' => $units($pending['overduePurchases'], 'Una compra vencida por pagar', 'compras vencidas por pagar'), 'url' => $route('purchases.payables')] : null,
            $pending['lowStock'] ? ['icon' => 'bi-box-seam', 'tone' => 'warning',
                'text' => $units($pending['lowStock'], 'Un insumo', 'insumos') . ' en o bajo el mínimo', 'url' => $route('products.index', ['filter' => 'bajo'])] : null,
            $pending['expiring'] ? ['icon' => 'bi-hourglass-bottom', 'tone' => 'warning',
                'text' => $units($pending['expiring'], 'Un lote vencido o por vencer', 'lotes vencidos o por vencer'), 'url' => $route('products.index', ['filter' => 'vencer'])] : null,
            $pending['followups'] ? ['icon' => 'bi-arrow-repeat', 'tone' => 'warning',
                'text' => $units($pending['followups'], 'Un control por avisar', 'controles por avisar'), 'url' => $route('communications.index', ['tipo' => 'seguimiento'])] : null,
            $pending['pendingCommissions'] > 0 ? ['icon' => 'bi-percent', 'tone' => 'warning',
                'text' => 'Comisiones por pagar: ' . Money::format($pending['pendingCommissions']), 'url' => $route('commissions.index')] : null,
        ]));
    }

    protected function can(string $permission): bool
    {
        return (bool) auth()->user()?->hasPermissionInCompany($permission, app(Tenancy::class)->company());
    }

    /** @return array<int, array{0: string, 1: array<int, string>, 2: array<int, array<int, mixed>>}> */
    protected function sheets(string $section, array $data, ReportPeriod $period): array
    {
        $range = $period->label();
        $money = $this->can('reports.finance');
        $chart = function (array $chart, string $title) use ($range) {
            $rows = [];
            foreach ($chart['labels'] as $i => $label) {
                $rows[] = array_merge([(string) $label], array_map(fn ($s) => $chart['format'] === 'money' ? (float) $s['values'][$i] : (int) $s['values'][$i], $chart['series']));
            }

            return ["{$title} · {$range}", array_merge(['Período'], array_column($chart['series'], 'name')), $rows];
        };
        $amounts = fn ($rows, string $title, string $column) => ["{$title} · {$range}", [$column, 'Importe', '% del total'],
            collect($rows)->map(fn ($r) => [$r['label'], (float) $r['value'], round($r['share'], 1)])->all()];
        $withQty = fn ($rows, string $title, string $column, string $qty = 'Cantidad') => ["{$title} · {$range}", [$column, $qty, 'Importe'],
            collect($rows)->map(fn ($r) => [$r['label'], (float) $r['quantity'], (float) $r['value']])->all()];
        $counts = fn ($rows, string $title, string $column, string $qty = 'Cantidad') => ["{$title} · {$range}", [$column, $qty],
            collect($rows)->map(fn ($r) => [$r['label'], (float) $r['value']])->all()];

        $finance = fn (array $d) => [
            ["Resumen financiero · {$range}", ['Concepto', 'Importe'], [
                ['Cobrado (ingresos)', $d['totals']['income']], ['Costo de productos vendidos', $d['totals']['products']],
                ['Insumos gastados en procedimientos', $d['totals']['supplies']], ['Gastos', $d['totals']['expenses']],
                ['Utilidad estimada', $d['totals']['profit']], ['Por cobrar a pacientes (hoy)', $d['receivable']], ['Por pagar (hoy)', $d['payable']],
            ]],
            $chart($d['chart'], 'Ingresos y gastos'),
            $amounts($d['byMethod'], 'Cobros por método de pago', 'Método'),
            $amounts($d['expensesByCategory'], 'Gastos por categoría', 'Categoría'),
            $amounts($d['debtors'], 'Por cobrar a pacientes', 'Paciente'),
            $amounts($d['creditors'], 'Por pagar a proveedores y laboratorios', 'Proveedor'),
        ];
        $sales = fn (array $d) => [
            $chart($d['chart'], 'Ventas por período'),
            $amounts($d['byType'], 'Ventas por origen', 'Origen'),
            $withQty($d['procedures'], 'Tratamientos vendidos', 'Procedimiento', 'Veces'),
            $withQty($d['products'], 'Productos vendidos', 'Producto', 'Unidades'),
            $amounts($d['specialties'], 'Ventas por especialidad', 'Especialidad'),
            $amounts($d['users'], 'Ventas por usuario', 'Usuario'),
        ];

        return match ($section) {
            'resumen' => array_merge($finance($data['finance']), $sales($data['sales'])),
            'finanzas' => $finance($data),
            'ventas' => $sales($data),
            'pacientes' => [
                ["Indicadores · {$range}", ['Indicador', 'Valor'], [
                    ['Consultas', $data['count']], ['Pacientes atendidos', $data['patients']], ['Pacientes nuevos', $data['newPatients']],
                    ['Citas', $data['appointments']['total']], ['Atendidas', $data['appointments']['attended']], ['No asistió', $data['appointments']['noShow']],
                    ['Canceladas', $data['appointments']['cancelled']], ['Asistencia (%)', $data['appointments']['attendance'] ?? ''],
                    ['Presupuestos presentados', $data['plans']['presented']], ['Presupuestos aprobados', $data['plans']['approved']],
                    ['Aceptación (%)', $data['plans']['acceptance'] ?? ''],
                ]],
                $chart($data['chart'], 'Consultas por período'),
                $counts($data['diagnoses'], 'Diagnósticos frecuentes', 'Diagnóstico', 'Casos'),
                $counts($data['procedures'], 'Procedimientos realizados', 'Procedimiento', 'Veces'),
                $counts($data['referrals'], 'Pacientes nuevos por origen', 'Cómo nos conoció', 'Pacientes'),
            ],
            'profesionales' => [["Producción por profesional · {$range}",
                array_merge(['Profesional', 'Consultas', 'Pacientes', 'Procedimientos', 'Citas', 'Asistencia (%)'], $money ? ['Atendido', 'Vendido', 'Comisiones'] : []),
                collect($data['rows'])->map(fn ($r) => array_merge([$r['professional']->full_name, $r['consultations'], $r['patients'], $r['procedures'], $r['appointments'], $r['attendance'] ?? ''],
                    $money ? [(float) $r['done'], (float) $r['sold'], (float) $r['commissions']] : []))->all()]],
            default => array_values(array_filter([
                $money ? ['Valor del inventario (hoy)', ['Producto', 'Existencia', 'Valor al costo'], collect($data['byProduct'])->map(fn ($r) => [$r['label'], (float) $r['quantity'], (float) $r['value']])->all()] : null,
                ['Bajo el mínimo (hoy)', ['Producto', 'Existencia', 'Mínimo'], collect($data['low'])->map(fn ($r) => [$r['label'], (float) $r['stock'], (float) $r['min']])->all()],
                ['Vencimientos (hoy)', ['Producto', 'Lote', 'Vence', 'Existencia', 'Consultorio'], collect($data['expiring'])
                    ->map(fn ($b) => [$b->product?->label, $b->lot, $b->expires_at?->format('d/m/Y'), (float) $b->quantity, $b->branch?->name])->all()],
                ["Movimientos · {$range}", ['Motivo', 'Entradas', 'Salidas'], collect($data['movements'])->map(fn ($r) => [$r['label'], $r['in'], $r['out']])->all()],
                $withQty($data['consumed'], 'Insumos gastados en procedimientos', 'Insumo', 'Cantidad'),
            ])),
        };
    }
}
