<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Supplier;
use App\Support\LabOrders;
use App\Support\Money;
use App\Support\PaymentSource;
use Illuminate\Validation\Rule;

/** Atención clínica › Laboratorio: órdenes de trabajo, su recorrido, impresión y pago al laboratorio. */
class LabOrderController extends Controller
{
    public const FILTERS = [
        'activos' => ['En curso', 'bi-hourglass-split'],
        'atrasados' => ['Atrasados', 'bi-exclamation-triangle'],
        'recibidos' => ['Por instalar', 'bi-box-arrow-in-down'],
        'pagar' => ['Por pagar', 'bi-cash-stack'],
        'instalados' => ['Instalados', 'bi-check2-all'],
        'todos' => ['Todos', 'bi-list-ul'],
    ];

    public function index()
    {
        $filter = array_key_exists(request('filter'), self::FILTERS) ? request('filter') : 'activos';
        $scope = fn ($q, string $f) => match ($f) {
            'activos' => $q->active(),
            'atrasados' => $q->whereIn('status', ['enviado', 'en_proceso'])->whereNotNull('due_at')->whereDate('due_at', '<', today()),
            'recibidos' => $q->where('status', 'recibido'),
            'pagar' => $q->unpaid(),
            'instalados' => $q->where('status', 'instalado'),
            default => $q,
        };
        $base = LabOrder::query()
            ->when(request('lab'), fn ($q, $l) => $q->where('supplier_id', $l))
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)));

        $orders = $scope((clone $base)->with(['patient', 'lab', 'professional']), $filter)
            ->orderByRaw("case when status in ('enviado','en_proceso') then 0 when status = 'recibido' then 1 else 2 end")
            ->orderBy('due_at')->latest('id')->paginate(30)->withQueryString();

        return view('admin.lab-orders.index', [
            'orders' => $orders,
            'filter' => $filter,
            'counts' => collect(self::FILTERS)->mapWithKeys(fn ($v, $k) => [$k => $scope(clone $base, $k)->count()]),
            'labs' => Supplier::whereIn('id', LabOrder::select('supplier_id'))->orderBy('name')->get(['id', 'name']),
            // «Por pagar», agrupado por laboratorio para pagar varias órdenes de una vez.
            'unpaidByLab' => $filter === 'pagar' ? LabOrder::with(['patient', 'lab'])->unpaid()
                ->when(request('lab'), fn ($q, $l) => $q->where('supplier_id', $l))->orderBy('number')->get()->groupBy('supplier_id') : collect(),
            'source' => $filter === 'pagar' ? PaymentSource::options() : null,
        ]);
    }

    public function create()
    {
        $patient = request('patient_id') ? Patient::findOrFail(request('patient_id')) : null;
        if (! $patient) {
            return view('admin.lab-orders.pick', [
                'patients' => request('q') ? Patient::where('active', true)->search(request('q'))->orderBy('first_name')->limit(20)->get() : collect(),
            ]);
        }

        return view('admin.lab-orders.form', $this->formData($patient) + ['order' => null, 'patient' => $patient]);
    }

    public function store()
    {
        $order = LabOrders::create(request()->all());

        return redirect()->route('lab-orders.show', $order)->with('success', "Orden {$order->code} registrada: {$order->summary}.");
    }

    public function show(LabOrder $order)
    {
        return view('admin.lab-orders.show', [
            'order' => $order->load(['patient', 'lab', 'professional', 'planItem.plan', 'consultation', 'expense', 'creator', 'logs.user']),
        ]);
    }

    public function edit(LabOrder $order)
    {
        return view('admin.lab-orders.form', $this->formData($order->patient) + ['order' => $order, 'patient' => $order->patient]);
    }

    public function update(LabOrder $order)
    {
        LabOrders::update($order, request()->all());

        return redirect()->route('lab-orders.show', $order)->with('success', 'Orden actualizada.');
    }

    public function status(LabOrder $order)
    {
        $data = request()->validate(['status' => ['required', Rule::in(array_keys(LabOrder::STATUSES))], 'note' => ['nullable', 'string', 'max:255']]);
        LabOrders::setStatus($order, $data['status'], $data['note'] ?? null);

        return back()->with('success', "{$order->code}: {$order->status_label}.");
    }

    /** Orden de trabajo imprimible para el laboratorio. */
    public function print(LabOrder $order)
    {
        return view('admin.lab-orders.print', ['order' => $order->load(['patient', 'lab', 'professional'])]);
    }

    public function pay(Supplier $lab)
    {
        $expense = LabOrders::pay($lab, array_map('intval', (array) request('orders', [])), request()->all());

        return back()->with('success', "Pago al laboratorio registrado: gasto {$expense->code} por " . Money::format($expense->amount) . '.');
    }

    protected function formData(Patient $patient): array
    {
        return [
            'labs' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'usedLabs' => LabOrder::select('supplier_id')->distinct()->pluck('supplier_id')->all(),
            'professionals' => Personal::professionals()->get(['id', 'full_name']),
            'planItems' => LabOrders::planItemsFor($patient),
            'defaultProfessional' => Personal::where('user_id', auth()->id())->value('id'),
        ];
    }
}
