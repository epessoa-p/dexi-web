<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Caja;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cajas de la clínica activa. Ruta con `tenant` + `plan:caja`: siempre hay
 * clínica, y CompanyScope filtra listados y route model binding.
 */
class CajaController extends Controller
{
    public function index()
    {
        $cajas = Caja::with('branch')->latest()->get();

        return view('admin.cajas.index', compact('cajas'));
    }

    public function create()
    {
        return view('admin.cajas.create', ['branches' => $this->branches()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        Caja::create($validated);   // company_id lo completa BelongsToCompany

        return redirect()->route('cajas.index')->with('success', 'Caja creada exitosamente.');
    }

    public function edit(Caja $caja)
    {
        return view('admin.cajas.edit', ['caja' => $caja, 'branches' => $this->branches()]);
    }

    public function update(Request $request, Caja $caja)
    {
        $caja->update($this->validated($request));

        return redirect()->route('cajas.index')->with('success', 'Caja actualizada exitosamente.');
    }

    public function destroy(Caja $caja)
    {
        $caja->delete();

        return redirect()->route('cajas.index')->with('success', 'Caja eliminada exitosamente.');
    }

    protected function branches()
    {
        return Branch::where('active', true)->orderBy('name')->get();
    }

    protected function validated(Request $request): array
    {
        $companyId = app(Tenancy::class)->id();

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|string|max:50',
            // El consultorio debe ser de la misma clínica.
            'branch_id'   => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'description' => 'nullable|string',
            'active'      => 'boolean',
        ]);

        $validated['active'] = $request->boolean('active', true);

        return $validated;
    }
}
