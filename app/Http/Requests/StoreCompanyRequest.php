<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    public function rules(): array
    {
        $companyId = $this->route('company')?->id;
        $creating = $companyId === null;

        return [
            'name' => 'required|string|max:255',
            'ruc' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'ruc')->ignore($companyId)],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string',
            'active' => 'sometimes|boolean',

            // Solo en el alta: plan inicial y administrador de la clínica (opcionales).
            'plan_id' => $creating ? ['nullable', Rule::exists('plans', 'id')->whereNull('deleted_at')] : ['prohibited'],
            'admin_email' => $creating ? ['nullable', 'email', 'max:255', 'unique:users,email'] : ['prohibited'],
            'admin_name' => $creating ? ['required_with:admin_email', 'nullable', 'string', 'max:255', 'regex:/^\S+$/', 'unique:users,name'] : ['prohibited'],
            'admin_password' => $creating ? ['required_with:admin_email', 'nullable', 'string', 'min:8'] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la clínica es requerido',
            'ruc.unique' => 'Este NIT / RUC ya está registrado',
            'admin_email.unique' => 'Ese email ya tiene una cuenta',
            'admin_name.unique' => 'Ese nombre de usuario ya existe',
            'admin_name.regex' => 'El usuario no puede contener espacios',
            'admin_name.required_with' => 'Indica el usuario del administrador',
            'admin_password.required_with' => 'Indica una contraseña para el administrador',
        ];
    }

    public function attributes(): array
    {
        return [
            'admin_email' => 'email del administrador',
            'admin_name' => 'usuario del administrador',
            'admin_password' => 'contraseña del administrador',
        ];
    }
}
