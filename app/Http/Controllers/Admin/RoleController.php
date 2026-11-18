<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->paginate(15);
        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        $permissions = Permission::forClinics()->get()->groupBy('module');
        return view('admin.roles.create', compact('permissions'));
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:roles,slug',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        $role = Role::create($validated);

        $role->permissions()->attach($this->clinicPermissionIds());

        return redirect()->route('roles.index')->with('success', 'Rol creado exitosamente');
    }

    public function show(Role $role)
    {
        $role->load(['permissions' => fn ($q) => $q->forClinics()]);
        return view('admin.roles.show', compact('role'));
    }

    public function edit(Role $role)
    {
        $permissions = Permission::forClinics()->get()->groupBy('module');
        $role->load('permissions');
        return view('admin.roles.edit', compact('role', 'permissions'));
    }

    public function update(Role $role)
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:roles,slug,' . $role->id,
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        // El slug de un rol estructural no cambia: el código lo busca por slug.
        if ($role->isProtected()) {
            $validated['slug'] = $role->slug;
        }

        $role->update($validated);

        // Sin casillas marcadas el rol queda sin permisos (antes se ignoraba).
        // Los de la plataforma no se ven en el formulario: el rol super_admin los conserva; los demás no los tienen.
        $ids = $this->clinicPermissionIds();
        if ($role->slug === 'super_admin') {
            $ids = $ids->merge(Permission::whereIn('module', Permission::PLATFORM_MODULES)->pluck('id'));
        }
        $role->permissions()->sync($ids->all());

        return redirect()->route('roles.index')->with('success', 'Rol actualizado exitosamente');
    }

    public function destroy(Role $role)
    {
        if ($role->isProtected()) {
            return back()->withErrors(['error' => 'Este rol es parte de la estructura del sistema y no se puede eliminar.']);
        }

        // Prevenir eliminar roles que están en uso
        if ($role->users()->exists() || \App\Models\Cargo::allCompanies()->where('role_id', $role->id)->exists()) {
            return back()->withErrors(['error' => 'No puedes eliminar un rol que está siendo utilizado']);
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Rol eliminado exitosamente');
    }

    /** Los permisos marcados, solo los de clínica (los de la plataforma no se reparten por formulario). */
    private function clinicPermissionIds(): \Illuminate\Support\Collection
    {
        return Permission::forClinics()->whereIn('id', (array) request('permissions', []))->pluck('id');
    }
}
