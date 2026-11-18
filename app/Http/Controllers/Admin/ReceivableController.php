<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Support\Cash;
use App\Support\Money;
use App\Support\Sales;

/** Cuentas por cobrar: quién debe, cuánto y desde cuándo; abonos a la cuenta del paciente. */
class ReceivableController extends Controller
{
    public function index()
    {
        PaymentMethod::ensureDefaults();

        $rows = Sale::receivable()->with('patient')
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->get()
            ->groupBy('patient_id')
            ->map(fn ($sales) => [
                'patient' => $sales->first()->patient,
                'balance' => round((float) $sales->sum('balance'), 2),
                'count' => $sales->count(),
                'oldest' => $sales->min('created_at'),
                'overdue' => $sales->contains(fn (Sale $s) => $s->isOverdue()),
            ])
            ->sortByDesc('balance')
            ->values();

        return view('admin.receivables.index', [
            'rows' => $rows,
            'total' => round($rows->sum('balance'), 2),
            'overdue' => $rows->where('overdue', true)->count(),
            'methods' => PaymentMethod::where('active', true)->orderBy('sort')->get(),
            'sessions' => Cash::enabled() ? Cash::openSessions() : collect(),
            'cashEnabled' => Cash::enabled(),
        ]);
    }

    /** Abono a la cuenta del paciente: se aplica a sus ventas con saldo, de la más antigua a la más nueva. */
    public function pay(Patient $patient)
    {
        $applied = Sales::payPatient($patient, request()->all());
        $detail = collect($applied)->map(fn ($amount, $code) => "{$code}: " . Money::format($amount))->implode(', ');

        return back()->with('success', "Abono de {$patient->full_name} registrado ({$detail}).");
    }
}
