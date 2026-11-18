<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Qué permisos trae cada rol del catálogo. Al crear los módulos dentales
 * (agenda, historia clínica…) se suman aquí sus permisos a cada rol.
 */
class RolePermissionSeeder extends Seeder
{
    public const MATRIX = [
        'odontologo' => ['reports.view', 'lab_orders.view', 'lab_orders.create', 'lab_orders.edit', 'products.view', 'communications.view', 'communications.create', 'sales.view', 'treatment_plans.view', 'treatment_plans.create', 'treatment_plans.edit', 'consultations.view', 'consultations.create', 'consultations.edit', 'odontogram.view', 'odontogram.edit', 'appointments.view', 'appointments.create', 'appointments.edit', 'schedules.view', 'procedures.view', 'personal.view', 'branches.view', 'patients.view', 'patients.create', 'patients.edit', 'patient_files.view', 'patient_files.create', 'patient_files.delete'],
        'asistente' => ['lab_orders.view', 'lab_orders.create', 'lab_orders.edit', 'products.view', 'inventory.view', 'inventory.create', 'communications.view', 'communications.create', 'treatment_plans.view', 'consultations.view', 'odontogram.view', 'odontogram.edit', 'appointments.view', 'appointments.create', 'appointments.edit', 'schedules.view', 'procedures.view', 'personal.view', 'branches.view', 'patients.view', 'patients.create', 'patients.edit', 'patient_files.view', 'patient_files.create'],
        'recepcionista' => ['reports.view', 'lab_orders.view', 'lab_orders.edit', 'products.view', 'purchases.view', 'communications.view', 'communications.create', 'sales.view', 'sales.create', 'receivables.view', 'receivables.create', 'treatment_plans.view', 'treatment_plans.edit', 'odontogram.view', 'appointments.view', 'appointments.create', 'appointments.edit', 'schedules.view', 'schedules.edit', 'procedures.view', 'personal.view', 'branches.view', 'patients.view', 'patients.create', 'patients.edit', 'patient_files.view', 'patient_files.create', 'cash.view', 'cash.open', 'cash.movements', 'cash.close'],
        'cajero' => ['products.view', 'sales.view', 'sales.create', 'receivables.view', 'receivables.create', 'treatment_plans.view', 'appointments.view', 'procedures.view', 'branches.view', 'patients.view', 'cajas.view', 'cash.view', 'cash.open', 'cash.movements', 'cash.close',
            'expenses.view', 'expenses.create', 'expense_categories.view', 'suppliers.view', 'suppliers.create'],
    ];

    public function run(): void
    {
        // El rol super_admin tiene absolutamente todo.
        Role::where('slug', 'super_admin')->first()
            ?->permissions()->sync(Permission::pluck('id'));

        // El admin de clínica tiene todo menos la administración de la plataforma.
        Role::where('slug', 'admin')->first()
            ?->permissions()->sync(Permission::whereNotIn('module', PermissionSeeder::SAAS_MODULES)->pluck('id'));

        foreach (self::MATRIX as $slug => $permissions) {
            Role::where('slug', $slug)->first()
                ?->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        }
    }
}
