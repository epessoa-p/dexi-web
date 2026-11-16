<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        // CompanyScope ya filtra por la clínica activa (o nada, en Modo Global).
        $companyId = auth()->user()->getCurrentCompany()?->id;

        return view('admin.branches.index', [
            'branches' => Branch::with('company')->latest()->paginate(15),
            'limit' => $this->planLimitStatus($companyId, 'branches'),
        ]);
    }

    public function create()
    {
        $user = auth()->user();

        return view('admin.branches.create', [
            'companies' => $this->companyOptions(),
        ]);
    }

    public function store()
    {
        try {
            $user = auth()->user();
            $companyId = $this->targetCompanyId();

            $validated = request()->validate([
                'company_id' => ['nullable', 'exists:companies,id'],
                'name' => 'required|string|max:255',
                'code' => ['nullable', 'string', 'max:50', Rule::unique('branches', 'code')->where('company_id', $companyId)->withoutTrashed()],
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:255',
                'manager_name' => 'nullable|string|max:255',
                'active' => 'sometimes|boolean',
            ]);

            if (empty($companyId)) {
                return back()->withInput()->withErrors(['company_id' => 'Debes seleccionar una clínica.']);
            }

            $company = Company::findOrFail($companyId);

            if ($this->planLimitReached($company->id, 'branches')) {
                return back()->withInput()->withErrors(['error' => $this->planLimitMessage($company->id, 'branches')]);
            }

            Branch::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'manager_name' => $validated['manager_name'] ?? null,
                'active' => request()->boolean('active', true),
            ]);

            return redirect()->route('branches.index')->with('success', 'Consultorio creado exitosamente.');
        } catch (\Throwable $exception) {
            Log::error('Error al crear consultorio', ['message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible crear el consultorio.']);
        }
    }

    public function show(Branch $branch)
    {
        $this->authorizeBranch($branch);
        $branch->load('company', 'cajas');

        return view('admin.branches.show', compact('branch'));
    }

    public function edit(Branch $branch)
    {
        $this->authorizeBranch($branch);
        $user = auth()->user();

        return view('admin.branches.edit', [
            'branch' => $branch,
            'companies' => $this->companyOptions(),
        ]);
    }

    public function update(Branch $branch)
    {
        $this->authorizeBranch($branch);

        try {
            $user = auth()->user();
            $companyId = $user->is_super_admin ? request('company_id', $branch->company_id) : $branch->company_id;

            $validated = request()->validate([
                'company_id' => ['nullable', 'exists:companies,id'],
                'name' => 'required|string|max:255',
                'code' => ['nullable', 'string', 'max:50', Rule::unique('branches', 'code')->where('company_id', $companyId)->withoutTrashed()->ignore($branch->id)],
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:255',
                'manager_name' => 'nullable|string|max:255',
                'active' => 'sometimes|boolean',
            ]);

            if (empty($companyId)) {
                return back()->withInput()->withErrors(['company_id' => 'Debes seleccionar una clínica.']);
            }

            $branch->update([
                ...$validated,
                'company_id' => $companyId,
                'active' => request()->boolean('active', false),
            ]);

            return redirect()->route('branches.index')->with('success', 'Consultorio actualizado exitosamente.');
        } catch (\Throwable $exception) {
            Log::error('Error al actualizar consultorio', ['branch_id' => $branch->id, 'message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible actualizar el consultorio.']);
        }
    }

    public function destroy(Branch $branch)
    {
        $this->authorizeBranch($branch);

        try {
            $branch->delete();
            return redirect()->route('branches.index')->with('success', 'Consultorio eliminado exitosamente.');
        } catch (\Throwable $exception) {
            Log::error('Error al eliminar consultorio', ['branch_id' => $branch->id, 'message' => $exception->getMessage()]);
            return back()->withErrors(['error' => 'No fue posible eliminar el consultorio.']);
        }
    }

    protected function authorizeBranch(Branch $branch): void
    {
        if (!auth()->user()->is_super_admin && $branch->company_id !== auth()->user()->getCurrentCompany()?->id) {
            abort(403);
        }
    }
}
