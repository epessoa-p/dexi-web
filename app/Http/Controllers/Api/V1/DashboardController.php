<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Models\Personal;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Communications;
use App\Support\Reports\ReportData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inicio de la app: saludo, agenda de hoy, lo que requiere atención y (para
 * quien ve dinero) lo cobrado. Cada bloque solo va si el plan tiene su módulo
 * y el rol su permiso: lo que no viene, la app no lo muestra.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $request->attributes->get('tenant_company');
        $can = fn (string $permission) => $user->hasPermissionInCompany($permission, $company);
        $module = fn (string $m) => (bool) $company?->planAllows($m);
        $hour = (int) now()->format('G');

        $today = null;
        $alerts = [];

        if ($module('agenda') && $can('appointments.view')) {
            $appointments = Appointment::with(['patient', 'professional', 'procedure'])->onDate(now())->orderBy('starts_at')->get();
            $mine = Personal::where('user_id', $user->id)->value('id');
            $open = $appointments->whereIn('status', ['agendada', 'confirmada', 'en_espera', 'en_atencion'])
                ->sortBy(fn ($a) => [array_search($a->status, ['en_atencion', 'en_espera', 'confirmada', 'agendada'], true) > 1 ? 1 : 0, $a->starts_at->timestamp]);
            $today = [
                'total' => $appointments->whereNotIn('status', ['cancelada', 'no_asistio'])->count(),
                'waiting' => $appointments->where('status', 'en_espera')->count(),
                'in_progress' => $appointments->where('status', 'en_atencion')->count(),
                'done' => $appointments->where('status', 'atendida')->count(),
                'mine' => $mine ? $appointments->where('personal_id', $mine)->whereNotIn('status', ['cancelada', 'no_asistio'])->count() : null,
                'next' => $open->take(8)->map(fn (Appointment $a) => AppointmentController::appointment($a))->values(),
            ];
            $unconfirmed = Appointment::onDate(now()->addDay())->where('status', 'agendada')->count();
            $alerts[] = $this->alert('waiting', 'En sala de espera', $today['waiting'], 'warning', 'Pacientes que ya llegaron');
            $alerts[] = $this->alert('unconfirmed', 'Citas de mañana sin confirmar', $unconfirmed, 'info', 'Confírmalas por WhatsApp');
        }

        if ($module('recordatorios') && $can('communications.view')) {
            $alerts[] = $this->alert('messages', 'WhatsApp por enviar', Communications::pendingCount(), 'success', 'Recordatorios, cumpleaños y controles');
        }
        if ($can('patients.view')) {
            $alerts[] = $this->alert('birthdays', 'Cumplen años hoy', Patient::where('active', true)->birthdayToday()->count(), 'primary');
        }
        if ($module('recordatorios') && $can('communications.view')) {
            $alerts[] = $this->alert('followups', 'Controles vencidos', PatientFollowup::pending()->whereDate('due_date', '<', today())->count(), 'warning');
        }
        if ($module('laboratorio') && $can('lab_orders.view')) {
            $alerts[] = $this->alert('lab_late', 'Trabajos de laboratorio atrasados', LabOrder::whereIn('status', ['enviado', 'en_proceso'])->whereNotNull('due_at')->whereDate('due_at', '<', today())->count(), 'danger');
            $alerts[] = $this->alert('lab_install', 'Trabajos por instalar', LabOrder::where('status', 'recibido')->count(), 'info', 'Llegaron del laboratorio: agenda al paciente');
        }
        if ($module('inventario') && $can('products.view')) {
            $alerts[] = $this->alert('low_stock', 'Insumos bajo el mínimo', Product::where('active', true)->lowStock()->count(), 'warning');
            $alerts[] = $this->alert('expiring', 'Lotes vencidos o por vencer', ProductBatch::expiring()->distinct()->count('product_id'), 'danger');
        }

        $finance = null;
        if ($module('ventas') && $can('sales.view')) {
            $paid = fn ($from, $to) => round((float) SalePayment::whereBetween('paid_at', [$from, $to])->whereHas('sale', fn ($q) => $q->valid())->sum('amount'), 2);
            $month = $paid(now()->startOfMonth(), now());
            $previous = $paid(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow());
            $receivables = Sale::receivable()->get(['balance', 'due_date', 'status']);
            $finance = [
                'today' => $paid(now()->startOfDay(), now()->endOfDay()),
                'sales_today' => Sale::valid()->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])->count(),
                'month' => $month,
                'month_delta' => ReportData::delta($month, $previous),
                'receivable' => round((float) $receivables->sum('balance'), 2),
                'receivable_overdue' => round((float) $receivables->filter(fn (Sale $s) => $s->isOverdue())->sum('balance'), 2),
            ];
        }

        return response()->json([
            'greeting' => $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches'),
            'today' => $today,
            'alerts' => array_values(array_filter($alerts, fn ($a) => $a['count'] > 0)),
            'finance' => $finance,
        ]);
    }

    private function alert(string $key, string $title, int $count, string $tone, ?string $hint = null): array
    {
        return ['key' => $key, 'title' => $title, 'count' => $count, 'tone' => $tone, 'hint' => $hint];
    }
}
