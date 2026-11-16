<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de usuarios. El permiso (users.create / users.edit) ya lo
 * exige la ruta con `check-permission`.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isUpdate = $userId !== null;
        $hasCompany = auth()->user()->getCurrentCompany() !== null || $this->filled('company_id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^\S+$/',
                Rule::unique('users', 'name')->ignore($userId),
            ],
            // El email no se edita desde el formulario (es el login); solo al crear.
            'email' => $isUpdate
                ? ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)]
                : ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'phone' => 'nullable|string|max:20',
            'is_super_admin' => 'sometimes|boolean',
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            // Dentro de una clínica todo usuario nuevo necesita un rol.
            'role_id' => [
                ! $isUpdate && $hasCompany && ! $this->boolean('is_super_admin') ? 'required' : 'nullable',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->where('slug', '!=', 'super_admin')->whereNull('deleted_at')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido',
            'name.regex' => 'El nombre de usuario no puede contener espacios',
            'email.required' => 'El email es requerido',
            'email.unique' => 'Este email ya está registrado',
            'name.unique' => 'Este nombre de usuario ya está registrado',
            'password.required' => 'La contraseña es requerida',
            'password.confirmed' => 'Las contraseñas no coinciden',
            'role_id.required' => 'Elige el rol que tendrá en la clínica',
        ];
    }
}
