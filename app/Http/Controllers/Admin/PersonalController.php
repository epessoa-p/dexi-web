<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Company;
use App\Models\Personal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonalController extends Controller
{
    public function index()
    {
        // CompanyScope ya filtra por la clínica activa (o nada, en Modo Global).
        $personals = Personal::with(['cargo.role', 'company', 'user'])->latest()->paginate(15);

        return view('admin.personal.index', compact('personals'));
    }

    public function create()
    {
        $authUser = auth()->user();
        $companyId = $this->targetCompanyId();

        $cargos = Cargo::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('active', true)
            ->with('role')
            ->orderBy('name')
            ->get();

        return view('admin.personal.create', [
            'cargos' => $cargos,
            'companies' => $this->companyOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();
        $companyId = (int) $this->targetCompanyId();

        // El usuario de acceso es opcional: solo se crea si se marca la casilla.
        $createUser = $request->boolean('create_user');

        try {
            $validated = $request->validate($this->rules($createUser));

            if (!$companyId) {
                return back()->withInput()->withErrors(['company_id' => 'Debes seleccionar una clínica.']);
            }

            $company = Company::findOrFail($companyId);

            if ($this->planLimitReached($company->id, 'personal')) {
                return back()->withInput()->withErrors(['error' => $this->planLimitMessage($company->id, 'personal')]);
            }

            if ($createUser && $this->planLimitReached($company->id, 'users')) {
                return back()->withInput()->withErrors(['error' => $this->planLimitMessage($company->id, 'users')]);
            }

            $cargo = Cargo::findOrFail($validated['cargo_id']);
            if ($cargo->company_id !== $companyId) {
                return back()->withInput()->withErrors(['cargo_id' => 'El cargo seleccionado no pertenece a la clínica actual.']);
            }

            DB::transaction(function () use ($validated, $companyId, $cargo, $request, $createUser) {
                $userId = null;

                if ($createUser) {
                    $userId = $this->createUserFor(
                        $validated['full_name'],
                        $validated['email'],
                        $validated['password'],
                        $validated['phone'] ?? null,
                        $companyId,
                        $cargo->role_id,
                        $request->boolean('active', true),
                    );
                }

                Personal::create([
                    'company_id' => $companyId,
                    'cargo_id' => $cargo->id,
                    'user_id' => $userId,
                    'full_name' => trim($validated['full_name']),
                    'id_number' => $validated['id_number'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'hire_date' => $validated['hire_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'active' => $request->boolean('active', true),
                ]);
            });

            return redirect()->route('personal.index')->with(
                'success',
                $createUser
                    ? 'Personal creado junto con su usuario de acceso.'
                    : 'Personal creado. No se generó usuario de acceso.',
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al crear personal', ['message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible crear el personal.']);
        }
    }

    public function edit(Personal $personal)
    {
        $this->authorizePersonal($personal);
        $authUser = auth()->user();

        $companyId = $authUser->is_super_admin
            ? $personal->company_id
            : $authUser->getCurrentCompany()?->id;

        $cargos = Cargo::where('company_id', $companyId)
            ->where('active', true)
            ->with('role')
            ->orderBy('name')
            ->get();

        $personal->load('user', 'cargo.role');

        return view('admin.personal.edit', [
            'personal' => $personal,
            'cargos' => $cargos,
            'companies' => $this->companyOptions(),
        ]);
    }

    public function update(Request $request, Personal $personal)
    {
        $this->authorizePersonal($personal);

        $authUser = auth()->user();
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id', $personal->company_id)
            : (int) $personal->company_id;

        $existingUser = $personal->user;
        // Si ya tiene acceso se mantiene; si no, solo se crea cuando se pide.
        $createUser = $existingUser === null && $request->boolean('create_user');
        $requiresUserFields = $existingUser !== null || $createUser;

        try {
            $validated = $request->validate($this->rules($createUser, $existingUser?->id, $requiresUserFields));

            $cargo = Cargo::findOrFail($validated['cargo_id']);
            if ($cargo->company_id !== $companyId) {
                return back()->withInput()->withErrors(['cargo_id' => 'El cargo seleccionado no pertenece a la clínica actual.']);
            }

            if ($createUser && $this->planLimitReached($companyId, 'users')) {
                return back()->withInput()->withErrors(['error' => $this->planLimitMessage($companyId, 'users')]);
            }

            DB::transaction(function () use ($validated, $personal, $cargo, $companyId, $request, $existingUser, $createUser) {
                $userId = $personal->user_id;

                if ($createUser) {
                    $userId = $this->createUserFor(
                        $validated['full_name'],
                        $validated['email'],
                        $validated['password'],
                        $validated['phone'] ?? null,
                        $companyId,
                        $cargo->role_id,
                        $request->boolean('active', false),
                    );
                } elseif ($existingUser) {
                    $userData = [
                        'email' => $validated['email'],
                        'phone' => $validated['phone'] ?? null,
                        'active' => $request->boolean('active', false),
                    ];

                    if (!empty($validated['password'])) {
                        $userData['password'] = Hash::make($validated['password']);
                    }

                    $existingUser->update($userData);

                    $existingUser->companies()->syncWithoutDetaching([
                        $companyId => ['role_id' => $cargo->role_id, 'active' => true],
                    ]);
                }

                $personal->update([
                    'company_id' => $companyId,
                    'cargo_id' => $cargo->id,
                    'user_id' => $userId,
                    'full_name' => trim($validated['full_name']),
                    'id_number' => $validated['id_number'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'hire_date' => $validated['hire_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'active' => $request->boolean('active', false),
                ]);
            });

            return redirect()->route('personal.index')->with(
                'success',
                $createUser ? 'Personal actualizado y usuario de acceso creado.' : 'Personal actualizado exitosamente.',
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al actualizar personal', ['personal_id' => $personal->id, 'message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible actualizar el personal.']);
        }
    }

    /**
     * Quita el acceso al sistema sin borrar el registro de personal.
     */
    public function revokeAccess(Personal $personal)
    {
        $this->authorizePersonal($personal);

        if (!$personal->user_id) {
            return back()->withErrors(['error' => 'Este personal no tiene usuario de acceso.']);
        }

        try {
            DB::transaction(function () use ($personal) {
                $user = $personal->user;

                $personal->update(['user_id' => null]);

                if ($user) {
                    $this->detachUserFromCompany($user, $personal->company_id);
                }
            });

            return back()->with('success', 'Acceso al sistema retirado. El registro de personal se mantiene.');
        } catch (\Throwable $exception) {
            Log::error('Error al retirar acceso de personal', ['personal_id' => $personal->id, 'message' => $exception->getMessage()]);
            return back()->withErrors(['error' => 'No fue posible retirar el acceso.']);
        }
    }

    public function destroy(Personal $personal)
    {
        $this->authorizePersonal($personal);

        DB::transaction(function () use ($personal) {
            $user = $personal->user;
            $personal->delete();
            if ($user) {
                $this->detachUserFromCompany($user, $personal->company_id);
            }
        });

        return redirect()->route('personal.index')->with('success', 'Registro de personal eliminado.');
    }

    /**
     * Reglas de validación. Los campos de acceso solo son obligatorios cuando
     * el personal va a tener (o ya tiene) usuario.
     */
    protected function rules(bool $createUser, ?int $ignoreUserId = null, bool $requiresUserFields = null): array
    {
        $requiresUserFields ??= $createUser;

        return [
            'company_id' => ['nullable', 'exists:companies,id'],
            'cargo_id' => ['required', 'exists:cargos,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => $requiresUserFields
                ? ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreUserId)]
                : ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'password' => $createUser
                ? ['required', 'string', 'min:8', 'confirmed']
                : ['nullable', 'string', 'min:8', 'confirmed'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Crea el usuario de acceso y lo vincula a la clínica con el rol del cargo.
     */
    protected function createUserFor(
        string $fullName,
        string $email,
        string $password,
        ?string $phone,
        int $companyId,
        ?int $roleId,
        bool $active,
    ): int {
        $user = User::create([
            'name' => $this->makeUniqueUsername($fullName),
            'email' => $email,
            'password' => Hash::make($password),
            'phone' => $phone,
            'active' => $active,
            'is_super_admin' => false,
        ]);

        $user->companies()->syncWithoutDetaching([
            $companyId => ['role_id' => $roleId, 'active' => true],
        ]);

        return $user->id;
    }

    /**
     * Quita el acceso del usuario a ESTA clínica. Un usuario puede pertenecer
     * a varias: solo se elimina la cuenta si ya no le queda ninguna.
     */
    protected function detachUserFromCompany(User $user, int $companyId): void
    {
        $user->companies()->detach($companyId);

        if (! $user->is_super_admin && $user->companies()->doesntExist()) {
            $user->delete();
        }
    }

    protected function authorizePersonal(Personal $personal): void
    {
        $authUser = auth()->user();
        if (!$authUser->is_super_admin && $personal->company_id !== $authUser->getCurrentCompany()?->id) {
            abort(403);
        }
    }

    protected function makeUniqueUsername(string $fullName): string
    {
        $base = Str::of($fullName)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', '')
            ->trim()
            ->replace(' ', '_')
            ->toString();

        if ($base === '') {
            $base = 'usuario';
        }

        $candidate = $base;
        $counter = 1;

        while (User::where('name', $candidate)->exists()) {
            $candidate = $base . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }
}
