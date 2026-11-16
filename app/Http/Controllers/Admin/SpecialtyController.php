<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Specialty;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;

/** Especialidades: agrupan el tarifario y dan color en la agenda. */
class SpecialtyController extends Controller
{
    public function index()
    {
        Specialty::ensureDefaults();

        return view('admin.specialties.index', [
            'specialties' => Specialty::withCount('procedures')->orderByDesc('active')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store()
    {
        $specialty = Specialty::create($this->validated() + ['sort_order' => (int) Specialty::max('sort_order') + 1]);

        return back()->with('success', "Especialidad {$specialty->name} agregada.");
    }

    public function update(Specialty $specialty)
    {
        $specialty->update($this->validated($specialty) + ['active' => request()->boolean('active')]);

        return back()->with('success', "Especialidad {$specialty->name} actualizada.");
    }

    /** Con procedimientos, solo se desactiva. */
    public function destroy(Specialty $specialty)
    {
        if ($specialty->procedures()->withTrashed()->exists()) {
            $specialty->update(['active' => false]);

            return back()->with('success', "{$specialty->name} tiene procedimientos: quedó inactiva.");
        }

        $specialty->delete();

        return back()->with('success', "Especialidad {$specialty->name} eliminada.");
    }

    /** @return array<string, mixed> */
    private function validated(?Specialty $specialty = null): array
    {
        return request()->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('specialties', 'name')->where('company_id', app(Tenancy::class)->id())->ignore($specialty?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'Esa especialidad ya existe.'], ['name' => 'nombre']);
    }
}
