<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Supplier;

/**
 * Proveedores: depósitos dentales, laboratorios, técnicos de equipos…
 * Los usa Gastos › Proveedores (y, cuando llegue, Compras).
 */
class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount(['expenses' => fn ($q) => $q->valid()])
            ->withSum(['expenses as paid' => fn ($q) => $q->valid()], 'amount')
            ->search(request('q'))
            ->when(request('status') === 'inactive', fn ($q) => $q->where('active', false), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('admin.suppliers.create', ['supplier' => null]);
    }

    public function store()
    {
        $supplier = Supplier::create($this->validateSupplier() + ['active' => request()->boolean('active', true)]);

        // «Nuevo proveedor» desde el alta de un pago vuelve a él con el proveedor elegido.
        if (request('return') === 'expense') {
            return redirect()->route('expenses.create', ['group' => 'proveedor', 'supplier_id' => $supplier->id])
                ->with('success', "Proveedor {$supplier->name} creado.");
        }

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor creado.');
    }

    public function show(Supplier $supplier)
    {
        $payments = Expense::where('supplier_id', $supplier->id);

        return view('admin.suppliers.show', [
            'supplier' => $supplier,
            'payments' => (clone $payments)->with(['category', 'account', 'cashSession.caja'])->latest('spent_at')->latest('id')->paginate(15),
            'paidTotal' => (float) (clone $payments)->valid()->sum('amount'),
            'paidThisYear' => (float) (clone $payments)->valid()->whereYear('spent_at', now()->year)->sum('amount'),
        ]);
    }

    public function edit(Supplier $supplier)
    {
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(Supplier $supplier)
    {
        $supplier->update($this->validateSupplier() + ['active' => request()->boolean('active')]);

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Supplier $supplier)
    {
        // Soft delete: sus pagos conservan el proveedor.
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Proveedor eliminado.');
    }

    /** @return array<string, mixed> */
    protected function validateSupplier(): array
    {
        return request()->validate([
            'name' => 'required|string|max:160',
            'tax_id' => 'nullable|string|max:32',
            'contact_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'active' => 'sometimes|boolean',
        ], [], ['name' => 'nombre o razón social', 'tax_id' => 'NIT / RUC']);
    }
}
