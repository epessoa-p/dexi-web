<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FinanceAccount;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\BasicServices;
use App\Support\PlanLimits;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Superadmin en Modo Global: métricas de la plataforma.
        if ($user->is_super_admin && ! $company) {
            return view('dashboard.index', [
                'isGlobal' => true,
                'company' => null,
                'metrics' => [
                    'companies' => Company::where('active', true)->count(),
                    'subscriptions' => Subscription::writable()->count(),
                    'trials' => Subscription::writable()->where('status', 'trial')->count(),
                    'expiring' => Subscription::expiringWithin(30)->count(),
                    'expired' => Subscription::lapsed()->count(),
                    'revenue' => SubscriptionPayment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
                    'users' => User::count(),
                    'personal' => Personal::count(),
                ],
                'expiringSoon' => Subscription::with(['company', 'plan'])
                    ->expiringWithin(30)
                    ->orderByPeriodEnd()
                    ->limit(8)
                    ->get(),
            ]);
        }

        // Dentro de una clínica: CompanyScope filtra todos los conteos. Cada
        // tarjeta solo se calcula si el plan tiene su módulo y el usuario su permiso.
        $can = fn (string $permission) => $user->hasPermissionInCompany($permission, $company);
        $module = fn (string ...$modules) => $company && collect($modules)->contains(fn ($m) => $company->planAllows($m));
        $finance = [];

        if ($module('caja', 'finanzas') && $can('expenses.view')) {
            $month = Expense::valid()->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
            $basics = BasicServices::month();
            $finance['expenses_month'] = round((float) (clone $month)->sum('amount'), 2);
            $finance['expenses_count'] = (clone $month)->count();
            $finance['basics_pending'] = $basics['pending'];
            $finance['basics_total'] = $basics['paid'] + $basics['pending'];
        }
        if ($module('ventas') && $can('sales.view')) {
            $paid = fn ($from, $to) => round((float) \App\Models\SalePayment::whereBetween('paid_at', [$from, $to])->whereHas('sale', fn ($q) => $q->valid())->sum('amount'), 2);
            $finance['income_today'] = $paid(now()->startOfDay(), now()->endOfDay());
            $finance['income_month'] = $paid(now()->startOfMonth(), now()->endOfMonth());
            $finance['receivable'] = round((float) \App\Models\Sale::receivable()->sum('balance'), 2);
        }
        if ($module('finanzas') && $can('accounts.view')) {
            $accounts = FinanceAccount::where('active', true)->get(['balance']);
            $finance['accounts_total'] = round((float) $accounts->sum('balance'), 2);
            $finance['accounts_count'] = $accounts->count();
        }
        if ($module('caja') && $can('cash.view')) {
            $open = CashSession::with('caja')->where('status', 'open')->get();
            $finance['cash_open'] = $open->count();
            $finance['cash_expected'] = round($open->sum(fn (CashSession $s) => $s->expectedCash()), 2);
        }

        $patients = null;
        if ($company && $can('patients.view')) {
            $patients = [
                'total' => Patient::where('active', true)->count(),
                'new_month' => Patient::where('created_at', '>=', now()->startOfMonth())->count(),
                'birthdays' => Patient::where('active', true)->birthdayToday()->orderBy('first_name')->limit(5)->get(),
                'missing_medical' => Patient::where('active', true)->whereNull('medical_updated_at')->count(),
            ];
        }

        $today = null;
        if ($module('agenda') && $can('appointments.view')) {
            $appointments = Appointment::with(['patient', 'professional', 'procedure'])->onDate(now())->orderBy('starts_at')->get();
            $today = [
                'total' => $appointments->whereNotIn('status', ['cancelada', 'no_asistio'])->count(),
                'waiting' => $appointments->where('status', 'en_espera')->count(),
                'done' => $appointments->where('status', 'atendida')->count(),
                // Lo que sigue abierto hoy (en atención y en espera primero), sin importar la hora:
                // un paciente atrasado o esperando también está pendiente.
                'next' => $appointments->whereIn('status', ['agendada', 'confirmada', 'en_espera', 'en_atencion'])
                    ->sortBy(fn ($a) => [array_search($a->status, ['en_atencion', 'en_espera', 'confirmada', 'agendada'], true) > 1 ? 1 : 0, $a->starts_at->timestamp])
                    ->take(6),
            ];
        }

        return view('dashboard.index', [
            'isGlobal' => false,
            'company' => $company,
            'finance' => $finance,
            'patients' => $patients,
            'today' => $today,
            // Comunicaciones: cuántos mensajes quedan por enviar (recordatorios de mañana, cumpleaños, controles…).
            // Inventario: productos bajo el mínimo y lotes por vencer.
            'inventory' => $module('inventario') && $can('products.view') ? [
                'low' => \App\Models\Product::where('active', true)->lowStock()->count(),
                'expiring' => \App\Models\ProductBatch::expiring()->distinct()->count('product_id'),
            ] : null,
            // Laboratorio: trabajos atrasados y los que llegan hoy.
            'lab' => $module('laboratorio') && $can('lab_orders.view') ? [
                'late' => \App\Models\LabOrder::whereIn('status', ['enviado', 'en_proceso'])->whereNotNull('due_at')->whereDate('due_at', '<', today())->count(),
                'today' => \App\Models\LabOrder::whereIn('status', ['enviado', 'en_proceso'])->whereDate('due_at', today())->count(),
                'install' => \App\Models\LabOrder::where('status', 'recibido')->count(),
            ] : null,
            'messages' => $module('recordatorios') && $can('communications.view') ? \App\Support\Communications::pendingCount() : null,
            'metrics' => [
                'users' => $company?->users()->wherePivot('active', true)->count() ?? 0,
                'personal' => Personal::count(),
                'branches' => Branch::count(),
            ],
            'subscription' => $company?->subscription,
            'usage' => $company ? PlanLimits::usage($company) : [],
        ]);
    }
}
