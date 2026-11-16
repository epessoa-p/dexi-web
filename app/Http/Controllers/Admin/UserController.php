<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Usuarios. User es un modelo GLOBAL (puede estar en varias clínicas), así que
 * el aislamiento se hace por la pertenencia en company_user:
 *
 *   - Usuario de clínica → solo ve y gestiona los miembros de su clínica activa.
 *   - Superadmin         → en Modo Global ve a todos; dentro de una clínica,
 *                          solo a los de esa clínica.
 */
class UserController extends Controller
{
    public function index()
    {
        $company = $this->company();

        $users = $company
            ? $company->users()->orderBy('name')->paginate(15)
            : User::orderBy('name')->paginate(15);

        return view('admin.users.index', [
            'users' => $users,
            'company' => $company,
            'limit' => $this->planLimitStatus($company?->id, 'users'),
        ]);
    }

    public function create()
    {
        return view('admin.users.create', [
            'roles' => $this->assignableRoles(),
            'company' => $this->company(),
            'companies' => auth()->user()->is_super_admin ? Company::orderBy('name')->get() : collect(),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $authUser = auth()->user();
        $companyId = $authUser->is_super_admin
            ? ($this->company()?->id ?? $request->integer('company_id') ?: null)
            : $this->company()?->id;

        if ($this->planLimitReached($companyId, 'users')) {
            return back()->withInput()->withErrors(['error' => $this->planLimitMessage($companyId, 'users')]);
        }

        try {
            DB::transaction(function () use ($request, $authUser, $companyId) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => $request->password,
                    'phone' => $request->phone,
                    // Solo el operador puede crear otro operador.
                    'is_super_admin' => $authUser->is_super_admin && $request->boolean('is_super_admin'),
                ]);

                if ($companyId && $request->filled('role_id')) {
                    $user->companies()->attach($companyId, ['role_id' => $request->integer('role_id'), 'active' => true]);
                }
            });

            return redirect()->route('users.index')->with('success', 'Usuario creado exitosamente');
        } catch (\Throwable $exception) {
            Log::error('Error al crear usuario', [
                'user' => $request->only('name', 'email'),
                'message' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'No fue posible crear el usuario.']);
        }
    }

    public function show(User $user)
    {
        $this->authorizeUser($user);

        $user->load('companies');

        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $this->authorizeUser($user);
        $company = $this->company();

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
            'company' => $company,
            'currentRoleId' => $company?->getRoleForUser($user)?->id,
        ]);
    }

    public function update(StoreUserRequest $request, User $user)
    {
        $this->authorizeUser($user);

        try {
            $data = collect($request->validated())->only(['name', 'phone', 'password'])->all();

            if (empty($data['password'])) {
                unset($data['password']);
            }

            if (auth()->user()->is_super_admin && $user->id !== auth()->id()) {
                $data['is_super_admin'] = $request->boolean('is_super_admin');
            }

            DB::transaction(function () use ($user, $data, $request) {
                $user->update($data);

                // Cambio de rol dentro de la clínica activa.
                if (($company = $this->company()) && $request->filled('role_id')) {
                    $user->companies()->updateExistingPivot($company->id, ['role_id' => $request->integer('role_id')]);
                }
            });

            return redirect()->route('users.index')->with('success', 'Usuario actualizado exitosamente');
        } catch (\Throwable $exception) {
            Log::error('Error al actualizar usuario', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'No fue posible actualizar el usuario.']);
        }
    }

    /**
     * Dentro de una clínica, "eliminar" = quitarle el acceso a esa clínica.
     * La cuenta solo se borra si ya no pertenece a ninguna (o lo pide el
     * superadmin en Modo Global).
     */
    public function destroy(User $user)
    {
        $this->authorizeUser($user);

        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'No puedes eliminar tu propio usuario']);
        }

        $company = $this->company();

        DB::transaction(function () use ($user, $company) {
            if ($company) {
                $user->companies()->detach($company->id);

                if (! $user->is_super_admin && $user->companies()->doesntExist()) {
                    $user->delete();
                }
            } else {
                $user->delete();
            }
        });

        return redirect()->route('users.index')->with('success', $company
            ? 'Se quitó el acceso del usuario a esta clínica.'
            : 'Usuario eliminado exitosamente');
    }

    /**
     * Asignar rol a usuario en una clínica
     */
    public function assignRole(User $user, Company $company, Role $role)
    {
        $authUser = auth()->user();

        if (! $authUser->is_super_admin && $this->company()?->id !== $company->id) {
            abort(403);
        }

        if ($role->slug === 'super_admin') {
            abort(403);
        }

        $user->companies()->syncWithoutDetaching([$company->id => ['role_id' => $role->id, 'active' => true]]);

        return back()->with('success', 'Rol asignado exitosamente');
    }

    /** Clínica activa (null = superadmin en Modo Global). */
    protected function company(): ?Company
    {
        return auth()->user()->getCurrentCompany();
    }

    /** Roles que una clínica puede asignar: nunca el del operador. */
    protected function assignableRoles()
    {
        return Role::where('slug', '!=', 'super_admin')->orderBy('name')->get();
    }

    /** Un usuario de otra clínica responde 404, igual que un dato de tenant ajeno. */
    protected function authorizeUser(User $user): void
    {
        $company = $this->company();

        if ($company === null) {
            abort_unless(auth()->user()->is_super_admin, 404);

            return;
        }

        abort_unless($user->companies()->whereKey($company->id)->exists(), 404);
    }
}
